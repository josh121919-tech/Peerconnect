<?php
// Settings — one page for both roles (the mentee and mentor copies were
// identical apart from two lines, so they're replaced by this).
// Tabs are server-side so the right-hand rail can change with them.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";
require_once __DIR__ . '/../../services/GoogleCalendarService.php';

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['mentee', 'mentor'], true)) {
    header("Location: " . url('welcomepage'));
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$is_mentor = $role === 'mentor';

$panels = ['account' => 'Account', 'notifications' => 'Notifications', 'privacy' => 'Data Privacy', 'security' => 'Security'];
$tab    = isset($_GET['tab'], $panels[$_GET['tab']]) ? $_GET['tab'] : 'account';

// ── Account ──────────────────────────────────────────────────────────────
$account = UserRepository::settingsAccount($con, $user_id);
$profile = ProfileRepository::fields($con, $user_id, ['full_name', 'club', 'course', 'year_level', 'profile_image', 'phone', 'location', 'birthdate', 'bio', 'visibility']) ?: [];

$full_name = trim($profile['full_name'] ?? '') !== ''
    ? $profile['full_name']
    : trim($account['firstname'] . ' ' . $account['lastname']);
$profile_image = $profile['profile_image'] ?? null;
$visibility    = $profile['visibility'] ?? 'everyone';

// ── Notification preferences and privacy ────────────────────────────────
$prefs = PreferenceRepository::notifications($con, $user_id)
    ?: ['session_requests' => 1, 'session_reminders' => 1, 'feedback_received' => 1, 'messages' => 0];

// Device notifications. Unconfigured servers say so plainly rather than
// offering a switch that silently does nothing.
$push_ready   = PushService::isConfigured($con);
$push_devices = PushSubscriptionRepository::countForUser($con, $user_id);
$privacy = PreferenceRepository::privacy($con, $user_id)
    ?: ['personalized_recommendations' => 1, 'share_activity' => 1, 'third_party_integrations' => 1];

$gcal_connected = false;
try {
    $gcal_connected = GoogleCalendarService::isConnected($con, $user_id);
} catch (Throwable $e) {
}

// ── Security ─────────────────────────────────────────────────────────────
// The activity feed and "last changed" both come from `logs`, which the
// sign-in flow already writes to, as do changing and resetting a password.
$account_email    = (string)($account['email'] ?? '');
$activity         = LogRepository::recentFor($con, $account_email, 8);
$password_changed = LogRepository::lastTime($con, $account_email, ['password changed', 'password reset completed']);

// An account made with Google has no password until its owner sets one
// through Forgot password.
$has_password = PasswordRepository::exists($con, $user_id);
$pw_min       = PasswordPolicy::minLength($con);

// Every check below is a fact about this account, not a placeholder.
$checks = [
    ['ok' => $has_password,                          'label' => 'Password set on your account',        'fix' => 'You sign in with Google. Use Forgot password to add a password as well.'],
    ['ok' => (int)$account['verified'] === 1,        'label' => 'Account verified by an admin',        'fix' => 'Submit your student ID for verification.'],
    ['ok' => ($account['status'] ?? '') === 'active', 'label' => 'Account in good standing',            'fix' => 'Your account is restricted — contact support.'],
];
if ($has_password) {
    // Only an account that has a password can still be on its first one.
    $checks[] = ['ok' => $password_changed !== null, 'label' => 'Password changed since sign-up', 'fix' => 'You are still on your original password.'];
}
$passed = count(array_filter($checks, fn($c) => $c['ok']));
$status_label = $passed === count($checks) ? 'Strong' : ($passed >= count($checks) - 2 ? 'Fair' : 'Needs attention');
$status_color = $passed === count($checks) ? 'var(--success)' : ($passed >= count($checks) - 2 ? 'var(--warning)' : 'var(--danger)');

$profile_url = $is_mentor ? url('mentor-profile') : url('mentee-profile');
$active_page = 'settings';
$csrf        = csrf_token();

function st_ago(?string $ts): string
{
    if (!$ts) return '—';
    return date('M j, Y · g:i A', strtotime($ts));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — PeerConnect</title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <style>
        .st-layout {
            display: grid;
            grid-template-columns: 216px minmax(0, 1fr) 300px;
            gap: 18px;
            align-items: start;
        }

        /* ── Tab rail ── */
        .st-nav {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 10px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .st-nav a {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 11px 13px;
            border-radius: var(--radius);
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-600);
            text-decoration: none;
            transition: background .14s, color .14s;
        }

        .st-nav a svg {
            width: 17px;
            height: 17px;
            flex-shrink: 0;
        }

        .st-nav a.active {
            background: var(--mint-faint);
            color: var(--mint);
        }

        .st-nav a:not(.active):hover {
            background: var(--gray-50);
            color: var(--forest);
        }

        /* ── Panel ── */
        .st-panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 24px;
        }

        .st-panel h2 {
            font-size: 19px;
            font-weight: 700;
            color: var(--forest);
            margin: 0;
        }

        .st-panel-sub {
            font-size: 13px;
            color: var(--gray-500);
            margin: 3px 0 0;
        }

        .st-section {
            font-size: 14px;
            font-weight: 700;
            color: var(--forest);
            margin: 24px 0 14px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }

        .st-section:first-of-type {
            border-top: none;
            padding-top: 0;
            margin-top: 22px;
        }

        .st-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px 18px;
        }

        .st-field label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 6px;
        }

        .st-input,
        .st-panel select.st-input,
        .st-panel textarea.st-input {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 10px 13px;
            font-family: inherit;
            font-size: 13px;
            color: var(--gray-800);
            background: var(--surface);
        }

        .st-input:focus {
            outline: none;
            border-color: var(--mint-soft);
        }

        .st-input:disabled {
            background: var(--gray-50);
            color: var(--gray-500);
        }

        textarea.st-input {
            min-height: 104px;
            resize: vertical;
        }

        .st-hint {
            font-size: 11.5px;
            color: var(--gray-400);
            margin-top: 5px;
        }

        /* ── Rows with a control on the right ── */
        .st-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 0;
            border-bottom: 1px solid var(--border);
        }

        .st-row:last-child {
            border-bottom: none;
        }

        .st-row-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--forest);
        }

        .st-row-desc {
            font-size: 12px;
            color: var(--gray-500);
            margin-top: 2px;
            line-height: 1.5;
        }

        .st-row-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: var(--mint-faint);
            color: var(--mint);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .st-row-icon svg {
            width: 17px;
            height: 17px;
        }

        /* ── Toggle ── */
        .st-toggle {
            position: relative;
            display: inline-block;
            width: 42px;
            height: 24px;
            flex-shrink: 0;
        }

        .st-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .st-toggle span {
            position: absolute;
            inset: 0;
            background: var(--gray-300);
            border-radius: 999px;
            cursor: pointer;
            transition: background .18s;
        }

        .st-toggle span::before {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            left: 3px;
            top: 3px;
            background: #fff;
            border-radius: 50%;
            transition: transform .18s;
        }

        .st-toggle input:checked+span {
            background: var(--success);
        }

        .st-toggle input:checked+span::before {
            transform: translateX(18px);
        }

        .st-toggle input:disabled+span {
            opacity: .5;
            cursor: not-allowed;
        }

        /* ── Data rights tiles ── */
        .st-rights {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .st-right-tile {
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px;
            text-align: left;
            background: var(--surface);
            cursor: pointer;
            font-family: inherit;
            text-decoration: none;
            display: block;
            transition: border-color .14s, box-shadow .14s;
        }

        .st-right-tile:hover {
            border-color: var(--mint-soft);
            box-shadow: var(--shadow-sm);
        }

        .st-right-tile.danger:hover {
            border-color: var(--danger);
        }

        /* ── Right rail ── */
        .st-preview-avatar {
            width: 104px;
            height: 104px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--mint-faint);
            color: var(--forest);
            font-size: 36px;
            font-weight: 700;
        }

        .st-meta-row {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 12.5px;
            color: var(--gray-600);
            padding: 5px 0;
        }

        .st-meta-row svg {
            width: 15px;
            height: 15px;
            color: var(--gray-400);
            flex-shrink: 0;
        }

        .st-action {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            text-decoration: none;
            margin-bottom: 10px;
            background: var(--surface);
            width: 100%;
            font-family: inherit;
            cursor: pointer;
            text-align: left;
        }

        .st-action:last-child {
            margin-bottom: 0;
        }

        .st-action:hover {
            border-color: var(--mint-soft);
        }

        .st-action.danger {
            border-color: #F3C9C9;
            background: var(--danger-bg);
        }

        .st-check {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 12.5px;
            padding: 6px 0;
        }

        .st-alert {
            border-radius: var(--radius);
            padding: 10px 14px;
            font-size: 12.5px;
            margin-bottom: 14px;
            display: none;
        }

        .st-alert.ok {
            display: block;
            background: var(--success-bg, #E8F5EF);
            color: var(--success);
        }

        .st-alert.bad {
            display: block;
            background: var(--danger-bg);
            color: var(--danger);
        }

        /* ── Page header ──────────────────────────────────────────────────
           .page-hd comes from the shared design system and is used on every
           screen, so the badge is added here rather than there. */
        /* .page-hd is a shared class with its own flex settings, so direction,
           wrapping and the text's basis are all stated here — otherwise the
           title wrapped onto the line below the badge instead of beside it. */
        .st-pagehd {
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: center;
            gap: 14px;
        }

        .st-pagehd-text {
            flex: 1 1 auto;
            min-width: 0;
        }

        .st-pagehd-ico {
            width: 48px;
            height: 48px;
            flex: 0 0 48px;
            border-radius: 15px;
            background: var(--info-bg);
            color: var(--navy);
            display: grid;
            place-items: center;
        }

        .st-pagehd-ico svg {
            width: 23px;
            height: 23px;
        }

        /* ── Panel heading ── */
        .st-panel-hd {
            display: flex;
            align-items: center;
            gap: 13px;
            margin-bottom: 4px;
        }

        .st-panel-hd-ico {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            border-radius: 13px;
            background: var(--info-bg);
            color: var(--navy);
            display: grid;
            place-items: center;
        }

        .st-panel-hd-ico svg {
            width: 20px;
            height: 20px;
        }

        .st-panel-hd .st-panel-sub {
            margin-top: 1px;
        }

        /* A bar marks where one group of settings starts, instead of the
           heading floating above the rule. */
        .st-section {
            position: relative;
            padding-left: 13px;
        }

        .st-section::before {
            content: '';
            position: absolute;
            left: 0;
            bottom: 1px;
            width: 4px;
            height: 15px;
            border-radius: 2px;
            background: var(--navy);
        }

        /* ── A field with an icon beside its box ──────────────────────────
           The icon sits outside the input, so nothing overlaps typed text and
           the input keeps its own padding and width. */
        .st-field-row {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .st-field-ico {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            border-radius: 12px;
            background: var(--info-bg);
            color: var(--navy);
            display: grid;
            place-items: center;
        }

        .st-field-ico svg {
            width: 18px;
            height: 18px;
        }

        .st-field-row .st-input {
            height: 42px;
        }

        @media (max-width: 1180px) {
            .st-layout {
                grid-template-columns: 200px minmax(0, 1fr);
            }
        }

        @media (max-width: 860px) {
            .st-layout {
                grid-template-columns: minmax(0, 1fr);
            }

            /* Four cards rather than a row that scrolls sideways. A tab you
               have to swipe to find is a tab most people never open, and there
               are only four of them — they fit. */
            .st-nav {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 8px;
                overflow: visible;
                padding: 8px;
            }

            .st-nav a {
                flex-direction: column;
                justify-content: center;
                gap: 6px;
                padding: 12px 6px;
                text-align: center;
                font-size: 11.5px;
                line-height: 1.25;
                white-space: normal;
                border: 1px solid transparent;
            }

            .st-nav a svg {
                width: 19px;
                height: 19px;
            }

            .st-nav a.active {
                border-color: var(--mint-soft, var(--mint));
            }

            .st-grid,
            .st-rights {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        /* Four across holds down to about 360px, where each tab still gets
           ~75px and only "Data Privacy" wraps to a second line. Below that it
           goes two by two rather than shrinking the text any further. */
        @media (max-width: 360px) {
            .st-nav {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 480px) {
            .st-pagehd-ico {
                width: 42px;
                height: 42px;
                flex-basis: 42px;
                border-radius: 13px;
            }

            .st-panel {
                padding: 18px 16px;
            }

            .st-field-ico {
                width: 38px;
                height: 38px;
                flex-basis: 38px;
            }
        }
    </style>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/../includes/app_shell.php'; ?>

        <main class="main fade-in">

            <div class="page-hd st-pagehd">
                <span class="st-pagehd-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <circle cx="12" cy="12" r="3.2" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.4 14.2a1.6 1.6 0 0 0 .32 1.77l.06.06a1.9 1.9 0 1 1-2.7 2.7l-.05-.06a1.6 1.6 0 0 0-1.78-.32 1.6 1.6 0 0 0-.97 1.47v.17a1.9 1.9 0 1 1-3.8 0v-.09a1.6 1.6 0 0 0-1.05-1.46 1.6 1.6 0 0 0-1.77.32l-.06.06a1.9 1.9 0 1 1-2.7-2.7l.06-.06a1.6 1.6 0 0 0 .32-1.77 1.6 1.6 0 0 0-1.47-.97H3.6a1.9 1.9 0 1 1 0-3.8h.09a1.6 1.6 0 0 0 1.46-1.05 1.6 1.6 0 0 0-.32-1.77l-.06-.06a1.9 1.9 0 1 1 2.7-2.7l.06.06a1.6 1.6 0 0 0 1.77.32h.08A1.6 1.6 0 0 0 10.35 4V3.8a1.9 1.9 0 1 1 3.8 0v.09a1.6 1.6 0 0 0 .97 1.46 1.6 1.6 0 0 0 1.77-.32l.06-.06a1.9 1.9 0 1 1 2.7 2.7l-.06.06a1.6 1.6 0 0 0-.32 1.77v.08a1.6 1.6 0 0 0 1.47.97h.17a1.9 1.9 0 1 1 0 3.8h-.09a1.6 1.6 0 0 0-1.46.97Z" />
                    </svg>
                </span>
                <span class="st-pagehd-text">
                    <h1>Settings</h1>
                    <p>Manage your account, preferences, and privacy settings.</p>
                </span>
            </div>

            <div class="st-layout">
                <!-- ═══ Tabs ═══ -->
                <nav class="st-nav">
                    <?php
                    $icons = [
                        'account'       => '<circle cx="12" cy="8" r="3.6"/><path stroke-linecap="round" d="M5 20c.7-3.5 3.5-5.4 7-5.4s6.3 1.9 7 5.4"/>',
                        'notifications' => '<path stroke-linecap="round" stroke-linejoin="round" d="M18 9a6 6 0 1 0-12 0c0 5-2 6-2 6h16s-2-1-2-6M13.7 21a2 2 0 0 1-3.4 0"/>',
                        'privacy'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7.5 3.2v5.1c0 4.3-3 8.2-7.5 9.7-4.5-1.5-7.5-5.4-7.5-9.7V6.2L12 3Z"/><path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4"/>',
                        'security'      => '<rect x="4.5" y="10" width="15" height="10" rx="2"/><path stroke-linecap="round" d="M8 10V7.5a4 4 0 0 1 8 0V10"/>',
                    ];
                    foreach ($panels as $key => $label): ?>
                        <a href="?tab=<?= $key ?>" class="<?= $key === $tab ? 'active' : '' ?>">
                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><?= $icons[$key] ?></svg>
                            <?= htmlspecialchars($label) ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <!-- ═══ Panel ═══ -->
                <div class="st-panel">
                    <div id="stAlert" class="st-alert"></div>

                    <?php if ($tab === 'account'): ?>
                        <div class="st-panel-hd">
                            <span class="st-panel-hd-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <circle cx="12" cy="8" r="3.6" />
                                    <path stroke-linecap="round" d="M5 20c.7-3.5 3.5-5.4 7-5.4s6.3 1.9 7 5.4" />
                                </svg>
                            </span>
                            <span>
                                <h2>Account Settings</h2>
                                <p class="st-panel-sub">Update your personal information and account preferences.</p>
                            </span>
                        </div>

                        <form id="stAccountForm" onsubmit="return saveAccount(event)">
                            <div class="st-section">Profile Information</div>
                            <div class="st-grid">
                                <?php // Icons sit beside each box, not inside it, so
                                      //     nothing overlaps what people type. ?>
                                <div class="st-field">
                                    <label for="f-name">Full Name</label>
                                    <div class="st-field-row">
                                        <span class="st-field-ico" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                                <circle cx="12" cy="8" r="3.6" />
                                                <path stroke-linecap="round" d="M5 20c.7-3.5 3.5-5.4 7-5.4s6.3 1.9 7 5.4" />
                                            </svg>
                                        </span>
                                        <input class="st-input" id="f-name" name="full_name" maxlength="100" required value="<?= htmlspecialchars($full_name) ?>">
                                    </div>
                                </div>
                                <div class="st-field">
                                    <label for="f-username">Username</label>
                                    <div class="st-field-row">
                                        <span class="st-field-ico" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                                <circle cx="12" cy="12" r="3.6" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.6 12v1.5a2.6 2.6 0 0 0 5.2 0V12a8.8 8.8 0 1 0-3.4 6.96" />
                                            </svg>
                                        </span>
                                        <input class="st-input" id="f-username" name="username" maxlength="30" placeholder="3–30 characters" value="<?= htmlspecialchars((string)$account['username']) ?>">
                                    </div>
                                </div>
                                <div class="st-field">
                                    <label for="f-email">Email Address</label>
                                    <div class="st-field-row">
                                        <span class="st-field-ico" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                                <rect x="3" y="5.5" width="18" height="13" rx="2.5" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m3.8 7 8.2 6 8.2-6" />
                                            </svg>
                                        </span>
                                        <input class="st-input" id="f-email" value="<?= htmlspecialchars($account['email']) ?>" disabled>
                                    </div>
                                    <div class="st-hint">Changed from the Security tab, so we can verify it's you.</div>
                                </div>
                                <div class="st-field">
                                    <label for="f-phone">Phone Number</label>
                                    <div class="st-field-row">
                                        <span class="st-field-ico" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.3 3.5h3l1.5 3.8-1.9 1.2a11.5 11.5 0 0 0 5.6 5.6l1.2-1.9 3.8 1.5v3a1.8 1.8 0 0 1-2 1.8A16.4 16.4 0 0 1 4.5 5.5a1.8 1.8 0 0 1 1.8-2Z" />
                                            </svg>
                                        </span>
                                        <input class="st-input" id="f-phone" name="phone" maxlength="25" placeholder="+63 912 345 6789" value="<?= htmlspecialchars((string)($profile['phone'] ?? '')) ?>">
                                    </div>
                                </div>
                                <div class="st-field">
                                    <label for="f-location">Location</label>
                                    <div class="st-field-row">
                                        <span class="st-field-ico" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s6.5-5.4 6.5-10.1A6.5 6.5 0 0 0 5.5 10.9C5.5 15.6 12 21 12 21Z" />
                                                <circle cx="12" cy="10.6" r="2.4" />
                                            </svg>
                                        </span>
                                        <input class="st-input" id="f-location" name="location" maxlength="120" placeholder="City, Country" value="<?= htmlspecialchars((string)($profile['location'] ?? '')) ?>">
                                    </div>
                                </div>
                                <div class="st-field">
                                    <label for="f-dob">Date of Birth</label>
                                    <div class="st-field-row">
                                        <span class="st-field-ico" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                                <rect x="3.5" y="5" width="17" height="15" rx="2.5" />
                                                <path stroke-linecap="round" d="M8 3.2v3.4M16 3.2v3.4M3.5 10h17" />
                                            </svg>
                                        </span>
                                        <input class="st-input" id="f-dob" name="birthdate" type="date" max="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars((string)($profile['birthdate'] ?? '')) ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="st-field" style="margin-top:14px;">
                                <label for="f-bio">Bio</label>
                                <textarea class="st-input" id="f-bio" name="bio" maxlength="500" oninput="document.getElementById('bioCount').textContent = this.value.length"
                                    placeholder="Tell mentors a little about yourself…"><?= htmlspecialchars((string)($profile['bio'] ?? '')) ?></textarea>
                                <div class="st-hint" style="text-align:right;"><span id="bioCount"><?= mb_strlen((string)($profile['bio'] ?? '')) ?></span>/500</div>
                            </div>

                            <div class="st-section">Account Preferences</div>
                            <div class="st-row">
                                <div>
                                    <div class="st-row-label">Profile Visibility</div>
                                    <div class="st-row-desc">
                                        <?= $is_mentor
                                            ? 'Private hides you from Find a Mentor. Existing mentees keep their sessions.'
                                            : 'Controls whether your bio and contact details appear on your public profile.' ?>
                                    </div>
                                </div>
                                <select class="st-input" name="visibility" id="f-visibility" style="max-width:230px;">
                                    <option value="everyone" <?= $visibility === 'everyone' ? 'selected' : '' ?>>Visible to everyone</option>
                                    <option value="mentors" <?= $visibility === 'mentors' ? 'selected' : '' ?>>Visible to connections only</option>
                                    <option value="private" <?= $visibility === 'private' ? 'selected' : '' ?>>Private</option>
                                </select>
                            </div>
                            <div class="st-row">
                                <div>
                                    <div class="st-row-label">Your Role</div>
                                    <div class="st-row-desc">Chosen when you signed up. Contact an admin if this needs to change.</div>
                                </div>
                                <span class="chip"><?= $is_mentor ? 'Mentor' : 'Mentee' ?></span>
                            </div>

                            <div style="display:flex;justify-content:flex-end;margin-top:22px;">
                                <button type="submit" class="btn btn-primary" id="stSaveBtn">Save Changes</button>
                            </div>
                        </form>

                    <?php elseif ($tab === 'notifications'): ?>
                        <h2>Notifications</h2>
                        <p class="st-panel-sub">Choose which alerts PeerConnect sends you.</p>

                        <div class="st-section">Alerts</div>
                        <?php
                        $notifs = [
                            ['session_requests',  'Session requests',      $is_mentor ? 'When a mentee requests a session with you.' : 'When a mentor approves or rejects your request.'],
                            ['session_reminders', 'Session reminders',     'A reminder shortly before a session starts.'],
                            ['feedback_received', 'Feedback received',     'When someone leaves you a review.'],
                            ['messages',          'Messages',              'When you get a new chat message.'],
                        ];
                        foreach ($notifs as [$key, $label, $desc]): ?>
                            <div class="st-row">
                                <div>
                                    <div class="st-row-label"><?= htmlspecialchars($label) ?></div>
                                    <div class="st-row-desc"><?= htmlspecialchars($desc) ?></div>
                                </div>
                                <label class="st-toggle">
                                    <input type="checkbox" data-pref="<?= $key ?>" <?= (int)$prefs[$key] ? 'checked' : '' ?> onchange="saveNotif(this)">
                                    <span></span>
                                </label>
                            </div>
                        <?php endforeach; ?>

                        <div class="st-section">On this device</div>
                        <?php /*
                            The switches above decide which notices are made at
                            all; this decides whether they also reach the phone in
                            your pocket. It is not another category — the same
                            notice, on a screen you are actually looking at.

                            The browser will only ask permission from a real
                            click, so this cannot be a toggle that flips itself on
                            when the page loads.
                        */ ?>
                        <?php if (!$push_ready): ?>
                            <div class="st-row">
                                <div>
                                    <div class="st-row-label">Notifications on your devices</div>
                                    <div class="st-row-desc">Not switched on for this site yet. An administrator has to set it up before devices can be added.</div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="st-row">
                                <div>
                                    <div class="st-row-label">Notifications on your devices</div>
                                    <div class="st-row-desc" id="pushDesc">
                                        Get these alerts on this device even when PeerConnect is closed.
                                        <?php if ($push_devices > 0): ?>
                                            <br><b><?= (int)$push_devices ?></b> device<?= $push_devices === 1 ? '' : 's' ?> currently set up.
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-primary btn-sm" id="pushBtn" onclick="pushToggle()" disabled>Checking…</button>
                            </div>
                        <?php endif; ?>

                    <?php elseif ($tab === 'privacy'): ?>
                        <h2>Data Privacy</h2>
                        <p class="st-panel-sub">Control how your data is used and shared on PeerConnect.</p>

                        <div class="st-section">Data Usage Preferences</div>
                        <?php
                        // Only switches that actually change behaviour somewhere in the app.
                        $toggles = [
                            [
                                'personalized_recommendations',
                                'Personalized recommendations',
                                'Let PeerConnect suggest mentors based on your subjects and past sessions. Off means the Dashboard and Matching show general results instead.',
                            ],
                            [
                                'share_activity',
                                'Show my activity publicly',
                                $is_mentor
                                    ? 'Include you in the Top Mentors leaderboard. Off hides you from it entirely.'
                                    : 'Let your session activity count towards public rankings and mentor stats.',
                            ],
                            [
                                'third_party_integrations',
                                'Allow third-party integrations',
                                'Required to sync your sessions with Google Calendar. Turning this off disconnects it.'
                                    . ($gcal_connected ? ' Google Calendar is connected right now.' : ''),
                            ],
                        ];
                        foreach ($toggles as [$key, $label, $desc]): ?>
                            <div class="st-row">
                                <div style="display:flex;gap:12px;">
                                    <span class="st-row-icon">
                                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7.5 3.2v5.1c0 4.3-3 8.2-7.5 9.7-4.5-1.5-7.5-5.4-7.5-9.7V6.2L12 3Z" /></svg>
                                    </span>
                                    <div>
                                        <div class="st-row-label"><?= htmlspecialchars($label) ?></div>
                                        <div class="st-row-desc"><?= htmlspecialchars($desc) ?></div>
                                    </div>
                                </div>
                                <label class="st-toggle">
                                    <input type="checkbox" data-privacy="<?= $key ?>" <?= (int)$privacy[$key] ? 'checked' : '' ?> onchange="savePrivacy(this)">
                                    <span></span>
                                </label>
                            </div>
                        <?php endforeach; ?>

                        <div class="st-section">Your Data Rights</div>
                        <p style="font-size:12.5px;color:var(--gray-500);margin:-6px 0 14px;">You have control over your personal data.</p>
                        <div class="st-rights">
                            <button type="button" class="st-right-tile" onclick="document.getElementById('dataModal').classList.add('open')">
                                <svg width="18" height="18" fill="none" stroke="var(--mint)" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" /><circle cx="12" cy="12" r="3" /></svg>
                                <div style="font-size:13px;font-weight:700;color:var(--forest);margin-top:8px;">View My Data</div>
                                <div style="font-size:11.5px;color:var(--gray-500);margin-top:2px;">See what information we store about you.</div>
                            </button>
                            <a class="st-right-tile" href="<?= htmlspecialchars(url('account-export-data')) ?>">
                                <svg width="18" height="18" fill="none" stroke="var(--mint)" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0 4-4m-4 4-4-4M5 19h14" /></svg>
                                <div style="font-size:13px;font-weight:700;color:var(--forest);margin-top:8px;">Download My Data</div>
                                <div style="font-size:11.5px;color:var(--gray-500);margin-top:2px;">Get a copy of your data as a JSON file.</div>
                            </a>
                            <button type="button" class="st-right-tile danger" onclick="document.getElementById('deleteModal').classList.add('open')">
                                <svg width="18" height="18" fill="none" stroke="var(--danger)" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M10 11v6M14 11v6M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13M9 7V4h6v3" /></svg>
                                <div style="font-size:13px;font-weight:700;color:var(--danger);margin-top:8px;">Request Data Deletion</div>
                                <div style="font-size:11.5px;color:var(--gray-500);margin-top:2px;">Permanently delete your account and data.</div>
                            </button>
                        </div>

                    <?php else: /* security */ ?>
                        <h2>Security</h2>
                        <p class="st-panel-sub">Keep your account safe and secure.</p>

                        <div class="st-section">Password</div>
                        <div class="st-row">
                            <div style="display:flex;gap:12px;">
                                <span class="st-row-icon">
                                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="4.5" y="10" width="15" height="10" rx="2" /><path stroke-linecap="round" d="M8 10V7.5a4 4 0 0 1 8 0V10" /></svg>
                                </span>
                                <div>
                                    <div class="st-row-label">Password</div>
                                    <div class="st-row-desc">
                                        <?php if (!$has_password): ?>
                                            You sign in with Google, so there's no password on this account.
                                        <?php else: ?>
                                            Last changed: <?= $password_changed ? htmlspecialchars(st_ago($password_changed)) : 'not since sign-up' ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if ($has_password): ?>
                                <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('pwBox').hidden = !document.getElementById('pwBox').hidden">Change Password</button>
                            <?php else: ?>
                                <a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars(url('forgot-password')) ?>">Add a Password</a>
                            <?php endif; ?>
                        </div>

                        <?php if ($has_password): ?>
                            <div id="pwBox" hidden style="border:1px solid var(--border);border-radius:var(--radius);padding:16px;margin-top:6px;">
                                <div class="st-grid">
                                    <div class="st-field">
                                        <label for="pw-cur">Current password</label>
                                        <input class="st-input" type="password" id="pw-cur" autocomplete="current-password">
                                    </div>
                                    <div class="st-field">
                                        <label for="pw-new">New password</label>
                                        <input class="st-input" type="password" id="pw-new" autocomplete="new-password" maxlength="20">
                                    </div>
                                    <div class="st-field">
                                        <label for="pw-confirm">Confirm new password</label>
                                        <input class="st-input" type="password" id="pw-confirm" autocomplete="new-password">
                                    </div>
                                </div>
                                <div style="background:var(--mint-faint);border-radius:var(--radius);padding:11px 14px;margin:12px 0;font-size:12px;color:var(--gray-600);line-height:1.6;">
                                    <b style="color:var(--forest);">Password tips:</b>
                                    <?= $pw_min ?>–<?= PasswordPolicy::MAX ?> characters · a mix of upper and lower case, a number and one of <?= htmlspecialchars(PasswordPolicy::SYMBOLS) ?> · avoid your name or birthdate.
                                </div>
                                <button class="btn btn-primary btn-sm" type="button" onclick="savePassword()">Save password</button>
                            </div>
                        <?php endif; ?>

                        <div class="st-section">Email Address</div>
                        <div class="st-row">
                            <div style="display:flex;gap:12px;">
                                <span class="st-row-icon">
                                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2" /><path stroke-linecap="round" d="m3.5 7 8.5 6 8.5-6" /></svg>
                                </span>
                                <div>
                                    <div class="st-row-label"><?= htmlspecialchars($account['email']) ?></div>
                                    <div class="st-row-desc">Used to sign in and to reach you about your sessions.</div>
                                </div>
                            </div>
                            <?php if ($has_password): ?>
                                <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('emailBox').hidden = !document.getElementById('emailBox').hidden">Change Email</button>
                            <?php endif; ?>
                        </div>
                        <?php if ($has_password): ?>
                            <div id="emailBox" hidden style="border:1px solid var(--border);border-radius:var(--radius);padding:16px;margin-top:6px;">
                                <div class="st-grid">
                                    <div class="st-field">
                                        <label for="em-new">New email address</label>
                                        <input class="st-input" type="email" id="em-new" autocomplete="email">
                                    </div>
                                    <div class="st-field">
                                        <label for="em-pw">Your password</label>
                                        <input class="st-input" type="password" id="em-pw" autocomplete="current-password">
                                    </div>
                                </div>
                                <button class="btn btn-primary btn-sm" type="button" style="margin-top:12px;" onclick="saveEmail()">Save email</button>
                            </div>
                        <?php else: ?>
                            <div class="prow-empty">
                                Changing your email asks for your password, and this account doesn't have one yet.
                                <a href="<?= htmlspecialchars(url('forgot-password')) ?>" style="color:var(--forest);font-weight:600;">Add a password</a> first.
                            </div>
                        <?php endif; ?>

                        <div class="st-section">Recent Security Activity</div>
                        <?php if (!$activity): ?>
                            <div class="prow-empty">No recorded activity yet.</div>
                        <?php else: ?>
                            <?php foreach ($activity as $a): ?>
                                <div class="st-row">
                                    <div style="display:flex;gap:12px;">
                                        <span class="st-row-icon"><?php
                                                                    $act = $a['activity'];
                                                                    if (strpos($act, 'password') !== false) {
                                                                        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="4.5" y="10" width="15" height="10" rx="2"/><path stroke-linecap="round" d="M8 10V7.5a4 4 0 0 1 8 0V10"/></svg>';
                                                                    } elseif (strpos($act, 'logout') !== false) {
                                                                        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17l5-5-5-5M20 12H9M12 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h6"/></svg>';
                                                                    } else {
                                                                        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17l-5-5 5-5M4 12h11M12 3h6a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1h-6"/></svg>';
                                                                    }
                                                                    ?></span>
                                        <div>
                                            <div class="st-row-label"><?= htmlspecialchars(ucfirst($a['activity'])) ?></div>
                                            <div class="st-row-desc"><?= htmlspecialchars(st_ago($a['log_date'])) ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <p class="st-hint" style="margin-top:12px;">
                                PeerConnect records sign-ins, sign-outs and password changes. Device and location are not collected.
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- ═══ Right rail ═══ -->
                <div style="display:flex;flex-direction:column;gap:16px;">
                    <?php if ($tab === 'account'): ?>
                        <div class="pcard">
                            <div class="pcard-hd">
                                <span class="pcard-title">Profile Preview</span>
                            </div>
                            <div class="pcard-body" style="text-align:center;">
                                <p style="font-size:12px;color:var(--gray-500);margin:0 0 14px;">This is how your profile appears to others.</p>
                                <?php if ($profile_image): ?>
                                    <img class="st-preview-avatar" src="<?= htmlspecialchars($profile_image) ?>" alt="">
                                <?php else: ?>
                                    <div class="st-preview-avatar"><?= htmlspecialchars(strtoupper(substr($full_name, 0, 1))) ?></div>
                                <?php endif; ?>
                                <div style="display:flex;align-items:center;justify-content:center;gap:8px;">
                                    <span style="font-size:17px;font-weight:700;color:var(--forest);"><?= htmlspecialchars($full_name) ?></span>
                                    <span class="chip" style="color:var(--mint);background:var(--mint-faint);"><?= $is_mentor ? 'Mentor' : 'Mentee' ?></span>
                                </div>
                                <?php if (trim((string)($profile['bio'] ?? '')) !== ''): ?>
                                    <p style="font-size:12.5px;color:var(--gray-600);font-style:italic;margin:8px 0 14px;line-height:1.5;">
                                        “<?= htmlspecialchars(mb_strimwidth((string)$profile['bio'], 0, 120, '…')) ?>”
                                    </p>
                                <?php else: ?>
                                    <p style="font-size:12px;color:var(--gray-400);margin:8px 0 14px;">No bio yet.</p>
                                <?php endif; ?>

                                <div style="text-align:left;">
                                    <div class="st-meta-row">
                                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2" /><path stroke-linecap="round" d="m3.5 7 8.5 6 8.5-6" /></svg>
                                        <?= htmlspecialchars($account['email']) ?>
                                    </div>
                                    <?php if (trim((string)($profile['location'] ?? '')) !== ''): ?>
                                        <div class="st-meta-row">
                                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-5.6 7-11a7 7 0 1 0-14 0c0 5.4 7 11 7 11Z" /><circle cx="12" cy="10" r="2.5" /></svg>
                                            <?= htmlspecialchars($profile['location']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="st-meta-row">
                                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="15" rx="2" /><path stroke-linecap="round" d="M3.5 9.5h17M8 3v4M16 3v4" /></svg>
                                        Joined <?= htmlspecialchars(date('F Y', strtotime($account['created_at']))) ?>
                                    </div>
                                </div>

                                <a href="<?= htmlspecialchars($profile_url) ?>" class="btn btn-ghost btn-sm" style="width:100%;justify-content:center;margin-top:14px;">
                                    View Public Profile
                                </a>
                            </div>
                        </div>

                        <div class="pcard">
                            <div class="pcard-hd">
                                <span class="pcard-title">Account Actions</span>
                            </div>
                            <div class="pcard-body">
                                <a class="st-action" href="<?= htmlspecialchars(url('account-export-data')) ?>">
                                    <span class="st-row-icon">
                                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0 4-4m-4 4-4-4M5 19h14" /></svg>
                                    </span>
                                    <span>
                                        <span style="display:block;font-size:13px;font-weight:700;color:var(--forest);">Download My Data</span>
                                        <span style="display:block;font-size:11.5px;color:var(--gray-500);">Get a copy of your data.</span>
                                    </span>
                                </a>
                                <button type="button" class="st-action danger" onclick="document.getElementById('deleteModal').classList.add('open')">
                                    <span class="st-row-icon" style="background:var(--danger-bg);color:var(--danger);">
                                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M10 11v6M14 11v6M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13M9 7V4h6v3" /></svg>
                                    </span>
                                    <span>
                                        <span style="display:block;font-size:13px;font-weight:700;color:var(--danger);">Delete Account</span>
                                        <span style="display:block;font-size:11.5px;color:var(--gray-500);">Permanently delete your account.</span>
                                    </span>
                                </button>
                            </div>
                        </div>

                    <?php elseif ($tab === 'security'): ?>
                        <div class="pcard">
                            <div class="pcard-hd">
                                <span class="pcard-title">Security Status</span>
                            </div>
                            <div class="pcard-body" style="text-align:center;">
                                <div style="font-size:26px;font-weight:800;color:<?= $status_color ?>;"><?= $status_label ?></div>
                                <div style="font-size:12px;color:var(--gray-500);margin-bottom:14px;"><?= $passed ?> of <?= count($checks) ?> checks passed</div>
                                <div style="text-align:left;">
                                    <?php foreach ($checks as $c): ?>
                                        <div class="st-check">
                                            <?php if ($c['ok']): ?>
                                                <svg width="16" height="16" fill="none" stroke="var(--success)" stroke-width="2.4" viewBox="0 0 24 24" style="flex-shrink:0;"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12 2.5 2.5 4.5-5" /></svg>
                                                <span style="color:var(--gray-700);"><?= htmlspecialchars($c['label']) ?></span>
                                            <?php else: ?>
                                                <svg width="16" height="16" fill="none" stroke="var(--gray-300)" stroke-width="2.4" viewBox="0 0 24 24" style="flex-shrink:0;"><circle cx="12" cy="12" r="9" /></svg>
                                                <span style="color:var(--gray-400);" title="<?= htmlspecialchars($c['fix']) ?>"><?= htmlspecialchars($c['label']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="pcard" style="background:var(--mint-faint);border-color:var(--mint-soft);">
                            <div class="pcard-body">
                                <div style="font-size:13px;font-weight:700;color:var(--forest);margin-bottom:4px;">Not what you expected?</div>
                                <div style="font-size:12px;color:var(--gray-600);line-height:1.55;">
                                    If you see a sign-in you don't recognise, change your password straight away and tell an admin.
                                </div>
                            </div>
                        </div>

                    <?php elseif ($tab === 'privacy'): ?>
                        <div class="pcard">
                            <div class="pcard-hd">
                                <span class="pcard-title">Data We Collect</span>
                            </div>
                            <div class="pcard-body" style="font-size:12.5px;color:var(--gray-600);line-height:1.7;">
                                Only what the mentorship service needs:
                                <ul style="margin:8px 0 0;padding-left:18px;">
                                    <li>Account details (name, email, role)</li>
                                    <li>Profile details (course, club, bio)</li>
                                    <li>Sessions, messages and feedback</li>
                                    <li>Sign-in activity</li>
                                </ul>
                                <p style="margin:10px 0 0;">Download the exact contents any time from Your Data Rights.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="pcard">
                            <div class="pcard-hd">
                                <span class="pcard-title">About notifications</span>
                            </div>
                            <div class="pcard-body" style="font-size:12.5px;color:var(--gray-600);line-height:1.6;">
                                These control the alerts in your notification bell and the emails that go with them.
                                Changes save the moment you flip a switch.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- View My Data -->
    <div id="dataModal" class="modal-overlay">
        <div style="background:var(--surface);border-radius:var(--radius-lg);padding:24px;width:560px;max-width:94vw;max-height:86vh;overflow-y:auto;box-shadow:var(--shadow-lg);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                <div style="font-size:16px;font-weight:700;color:var(--forest);">What we store about you</div>
                <button type="button" onclick="document.getElementById('dataModal').classList.remove('open')" style="width:30px;height:30px;border-radius:8px;border:1px solid var(--gray-200);background:var(--surface);cursor:pointer;">✕</button>
            </div>
            <p style="font-size:12.5px;color:var(--gray-500);margin:0 0 16px;">Counted live from your account.</p>
            <div id="dataCounts" style="font-size:13px;color:var(--gray-700);">Loading…</div>
            <a href="<?= htmlspecialchars(url('account-export-data')) ?>" class="btn btn-primary btn-sm" style="margin-top:18px;">Download the full copy</a>
        </div>
    </div>

    <!-- Delete account -->
    <div id="deleteModal" class="modal-overlay">
        <div style="background:var(--surface);border-radius:var(--radius-lg);padding:24px;width:460px;max-width:94vw;box-shadow:var(--shadow-lg);">
            <div style="font-size:16px;font-weight:700;color:var(--danger);margin-bottom:6px;">Delete your account</div>
            <p style="font-size:12.5px;color:var(--gray-600);line-height:1.6;">
                This blocks your access and clears your profile details. Session history is kept so other people's
                records stay intact. This cannot be undone.
            </p>
            <p style="font-size:12.5px;color:var(--gray-600);">Type <b><?= htmlspecialchars($account['email']) ?></b> to confirm.</p>
            <input class="st-input" type="email" id="delConfirm" placeholder="<?= htmlspecialchars($account['email']) ?>">
            <div style="display:flex;gap:10px;margin-top:16px;">
                <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('deleteModal').classList.remove('open')">Cancel</button>
                <button class="btn btn-sm" type="button" style="background:var(--danger);border-color:var(--danger);color:#fff;" onclick="deleteAccount()">Delete permanently</button>
            </div>
        </div>
    </div>

    <script>
        const ST_CSRF = <?= json_encode($csrf) ?>;
        const MY_EMAIL = <?= json_encode($account['email']) ?>;

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        }

        function toggleProfileMenu() {
            document.getElementById('profileMenu').classList.toggle('open');
        }
        document.addEventListener('click', function(e) {
            const m = document.getElementById('profileMenu');
            if (m && !e.target.closest('#profileMenu') && !e.target.closest('button[onclick="toggleProfileMenu()"]'))
                m.classList.remove('open');
        });

        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.addEventListener('click', e => {
                if (e.target === m) m.classList.remove('open');
            });
        });

        // Settings used to answer in a banner of its own, above the form and
        // sometimes off-screen — which meant saving a toggle near the bottom
        // of the page confirmed itself somewhere you were not looking. It
        // goes to the same corner as every other confirmation in the app now.
        // The inline element stays as the fallback for a page loaded before
        // the shared toast script.
        function flash(msg, ok) {
            if (typeof pcToast === 'function') {
                pcToast(msg, ok ? 'success' : 'error', ok ? 3500 : 6000, ok ? 'Saved' : '');
                return;
            }
            const el = document.getElementById('stAlert');
            if (!el) return;
            el.textContent = msg;
            el.className = 'st-alert ' + (ok ? 'ok' : 'bad');
            el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            if (ok) setTimeout(() => { el.className = 'st-alert'; }, 5000);
        }

        async function post(url, data) {
            const body = new URLSearchParams(Object.assign({
                csrf_token: ST_CSRF
            }, data));
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: body.toString()
            });
            return res.json();
        }

        // ── Account ──────────────────────────────────────────────────────
        async function saveAccount(e) {
            e.preventDefault();
            const btn = document.getElementById('stSaveBtn');
            btn.disabled = true;
            try {
                const d = await post('<?= url('account-update-account') ?>', {
                    full_name: document.getElementById('f-name').value,
                    username: document.getElementById('f-username').value,
                    phone: document.getElementById('f-phone').value,
                    location: document.getElementById('f-location').value,
                    birthdate: document.getElementById('f-dob').value,
                    bio: document.getElementById('f-bio').value,
                    visibility: document.getElementById('f-visibility').value
                });
                flash(d.message, !!d.success);
                if (d.success) setTimeout(() => location.reload(), 900);
            } catch (err) {
                flash('Network error. Please try again.', false);
            } finally {
                btn.disabled = false;
            }
            return false;
        }

        /* ── Notifications on this device ──
           The subscription is made by the browser and only becomes ours once
           it has been posted here; the reverse on the way out, so a device
           that was told no is not left on a list we keep writing to. */
        <?php if (!empty($push_ready)): ?>
        const VAPID_PUBLIC = <?= json_encode(VAPID_PUBLIC_KEY) ?>;
        const PUSH_SUB_URL = <?= json_encode(url('push-subscribe')) ?>;
        const PUSH_UNSUB_URL = <?= json_encode(url('push-unsubscribe')) ?>;

        /* The Push API wants the application server key as raw bytes. */
        function pushKeyBytes(b64) {
            const pad = '='.repeat((4 - (b64.length % 4)) % 4);
            const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
            return Uint8Array.from(raw, c => c.charCodeAt(0));
        }

        async function pushSub() {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) return undefined;
            const reg = await navigator.serviceWorker.ready;
            return reg.pushManager.getSubscription();
        }

        async function pushPaint() {
            const btn = document.getElementById('pushBtn');
            if (!btn) return;

            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
                btn.textContent = 'Not supported';
                btn.disabled = true;
                document.getElementById('pushDesc').textContent =
                    'This browser cannot show notifications when PeerConnect is closed. Try Chrome, Edge or Firefox.';
                return;
            }
            if (Notification.permission === 'denied') {
                btn.textContent = 'Blocked';
                btn.disabled = true;
                document.getElementById('pushDesc').textContent =
                    'You have blocked notifications for this site. Allow them in your browser\u2019s site settings, then reload this page.';
                return;
            }

            const sub = await pushSub();
            btn.disabled = false;
            btn.textContent = sub ? 'Turn off' : 'Turn on';
            btn.classList.toggle('btn-primary', !sub);
            btn.classList.toggle('btn-ghost', !!sub);
        }

        async function pushToggle() {
            const btn = document.getElementById('pushBtn');
            btn.disabled = true;
            try {
                const existing = await pushSub();

                if (existing) {
                    await post(PUSH_UNSUB_URL, { endpoint: existing.endpoint });
                    await existing.unsubscribe();
                    flash('This device will no longer be notified.', true);
                } else {
                    // Asked from the click, which is the only time a browser listens.
                    const perm = await Notification.requestPermission();
                    if (perm !== 'granted') {
                        flash('Notifications were not allowed, so nothing changed.', false);
                        await pushPaint();
                        return;
                    }
                    const reg = await navigator.serviceWorker.ready;
                    const sub = await reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: pushKeyBytes(VAPID_PUBLIC),
                    });
                    const j = sub.toJSON();
                    const d = await post(PUSH_SUB_URL, {
                        endpoint: j.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth,
                    });
                    if (!d || !d.ok) {
                        // Ours did not take it, so do not leave the browser thinking
                        // it is subscribed to something that will never arrive.
                        await sub.unsubscribe();
                        flash((d && d.error) || 'That device could not be saved.', false);
                    } else {
                        flash('This device will now be notified.', true);
                    }
                }
            } catch (e) {
                flash('Something went wrong setting that up. Please try again.', false);
            }
            await pushPaint();
        }

        pushPaint();
        <?php endif; ?>

        // ── Notifications ────────────────────────────────────────────────
        async function saveNotif(el) {
            el.disabled = true;
            try {
                const d = await post('<?= url('account-update-notif-pref') ?>', {
                    pref: el.dataset.pref,
                    enabled: el.checked ? 1 : 0
                });
                if (!d.success) {
                    el.checked = !el.checked;
                    flash(d.message || 'Could not save that preference.', false);
                } else {
                    flash('Notification preference saved.', true);
                }
            } catch (err) {
                el.checked = !el.checked;
                flash('Network error. Please try again.', false);
            } finally {
                el.disabled = false;
            }
        }

        // ── Privacy ──────────────────────────────────────────────────────
        async function savePrivacy(el) {
            el.disabled = true;
            try {
                const d = await post('<?= url('account-update-privacy') ?>', {
                    key: el.dataset.privacy,
                    value: el.checked ? 1 : 0
                });
                if (!d.success) {
                    el.checked = !el.checked;
                    flash(d.message || 'Could not save that setting.', false);
                } else {
                    flash(d.message, true);
                }
            } catch (err) {
                el.checked = !el.checked;
                flash('Network error. Please try again.', false);
            } finally {
                el.disabled = false;
            }
        }

        // ── Security ─────────────────────────────────────────────────────
        async function savePassword() {
            const cur = document.getElementById('pw-cur').value;
            const nw = document.getElementById('pw-new').value;
            const cf = document.getElementById('pw-confirm').value;
            if (!cur || !nw || !cf) return flash('Fill in all three password fields.', false);
            if (nw !== cf) return flash('The new passwords do not match.', false);
            try {
                const d = await post('<?= url('account-update-password') ?>', {
                    current_password: cur,
                    new_password: nw,
                    confirm_new_password: cf
                });
                flash(d.message, !!d.success);
                if (d.success) setTimeout(() => location.reload(), 900);
            } catch (err) {
                flash('Network error. Please try again.', false);
            }
        }

        async function saveEmail() {
            const em = document.getElementById('em-new').value.trim();
            const pwEl = document.getElementById('em-pw');
            if (!em) return flash('Enter the new email address.', false);
            try {
                const d = await post('<?= url('account-update-email') ?>', {
                    new_email: em,
                    confirm_password: pwEl ? pwEl.value : ''
                });
                flash(d.message, !!d.success);
                if (d.success) setTimeout(() => location.reload(), 900);
            } catch (err) {
                flash('Network error. Please try again.', false);
            }
        }

        // ── Data rights ──────────────────────────────────────────────────
        const dataModal = document.getElementById('dataModal');
        if (dataModal) {
            const obs = new MutationObserver(async () => {
                if (!dataModal.classList.contains('open')) return;
                const box = document.getElementById('dataCounts');
                if (box.dataset.loaded) return;
                try {
                    const res = await fetch('<?= url('account-export-data') ?>');
                    const d = await res.json();
                    const items = [
                        ['Account record', d.account ? 1 : 0],
                        ['Profile details', d.profile ? 1 : 0],
                        ['Verification submissions', (d.verification || []).length],
                        ['Sessions', (d.sessions || []).length],
                        ['Reviews you wrote', (d.feedback_given || []).length],
                        ['Reviews about you', (d.feedback_received || []).length],
                        ['Messages', (d.messages || []).length],
                        ['Assessment attempts', (d.assessment_attempts || []).length],
                        ['Resources you uploaded', (d.resources_uploaded || []).length],
                        ['Interests and skills', (d.interests_and_skills || []).length],
                        ['Goals', (d.goals || []).length],
                        ['Reviews mentors wrote about you', (d.reviews_from_mentors || []).length],
                        ['Reviews you wrote about mentees', (d.reviews_written_about_mentees || []).length],
                        ['Review drafts', (d.review_drafts || []).length],
                        ['Session attendance records', (d.session_attendance || []).length],
                        ['Time recorded in calls', (d.session_presence || []).length],
                        ['Availability slots', (d.availability || []).length],
                        ['Assessments you created', (d.assessments_created || []).length],
                        ['Badges', (d.badges || []).length],
                        ['Certificates', (d.certificates || []).length],
                        ['Saved resources', (d.saved_resources || []).length],
                        ['Reports you filed', (d.reports_filed || []).length],
                        ['Notifications', (d.notifications || []).length],
                        ['Sign-in activity entries', (d.activity_log || []).length]
                    ].filter(([k, v]) => v > 0 || ['Account record', 'Profile details', 'Sessions', 'Messages'].includes(k));
                    box.innerHTML = items.map(([k, v]) =>
                        '<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">' +
                        '<span style="color:var(--gray-600);">' + k + '</span><b style="color:var(--forest);">' + v + '</b></div>'
                    ).join('');
                    box.dataset.loaded = '1';
                } catch (e) {
                    box.textContent = 'Could not load your data summary.';
                }
            });
            obs.observe(dataModal, {
                attributes: true,
                attributeFilter: ['class']
            });
        }

        async function deleteAccount() {
            const typed = document.getElementById('delConfirm').value.trim();
            if (typed.toLowerCase() !== MY_EMAIL.toLowerCase()) {
                return flash('Type your email exactly to confirm.', false);
            }
            try {
                const d = await post('<?= url('account-delete') ?>', {
                    confirm_email: typed
                });
                if (d.success) {
                    window.location.href = <?= json_encode(url('logout')) ?>;
                } else {
                    flash(d.message || 'Could not delete the account.', false);
                }
            } catch (err) {
                flash('Network error. Please try again.', false);
            }
        }
    </script>
</body>

</html>
