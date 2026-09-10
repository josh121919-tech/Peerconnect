<?php

/**
 * admin/settings_integrations.php — System Settings → Integrations.
 *
 * Only the services this app actually talks to. The reference design showed
 * cards for Microsoft 365, Twilio, Zoom, Dropbox, OneDrive, Slack and OpenAI;
 * none of them exist in this codebase, and a toggle labelled "Connected" over
 * an integration with no code behind it is a lie an admin would act on.
 *
 * Each card below reports state that was checked, not stored: whether the
 * keys are present in .env, and — for Google Calendar — how many people have
 * actually linked an account.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/settings_store.php';
require_once __DIR__ . '/../../services/EmailService.php';
require_once __DIR__ . '/../../services/GoogleCalendarService.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$csrf = csrf_token();

$one = function (string $sql) use ($con) {
    $r = $con->query($sql);
    return $r ? $r->fetch_row()[0] : null;
};

$gcalLinked  = (int)$one("SELECT COUNT(*) FROM google_calendar_links");
$googleUsers = (int)$one("SELECT COUNT(*) FROM logs WHERE activity LIKE '%via google%'");
$mailSent    = (int)$one("SELECT COUNT(*) FROM notifications WHERE email_status = 'sent'");
$videoRooms  = (int)$one("SELECT COUNT(*) FROM session_requests WHERE status IN ('approved','completed')");

/*
 * Each entry: does the app contain code for it, is it configured, and what
 * can be said about its use. `configured` is checked here and now.
 */
$integrations = [
    [
        'name'   => 'Google Sign-In',
        'group'  => 'Authentication',
        'desc'   => 'Members can sign in and sign up with a Google account.',
        'colour' => ['#1A5C9A', '#EAF1FB'],
        'ready'  => ($_ENV['GOOGLE_CLIENT_ID'] ?? '') !== '' && ($_ENV['GOOGLE_CLIENT_SECRET'] ?? '') !== '',
        'usage'  => $googleUsers > 0 ? $googleUsers . ' sign-ins so far' : 'No Google sign-ins yet',
        'env'    => 'GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET',
        'where'  => 'App/controllers/google-login.php',
    ],
    [
        'name'   => 'Google Calendar',
        'group'  => 'Productivity',
        'desc'   => 'Two-way sync so booked sessions appear in a member’s own calendar.',
        'colour' => ['#17654B', '#E6F5EE'],
        'ready'  => GoogleCalendarService::isConfigured(),
        'usage'  => $gcalLinked > 0 ? $gcalLinked . ' ' . ($gcalLinked === 1 ? 'account has' : 'accounts have') . ' linked a calendar' : 'Nobody has linked a calendar',
        'env'    => 'GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET',
        'where'  => 'App/services/GoogleCalendarService.php',
    ],
    [
        'name'   => 'Email (SMTP)',
        'group'  => 'Communication',
        'desc'   => 'Sends notification and password-reset email through your mail server.',
        'colour' => ['#0087CF', '#EAF6FB'],
        'ready'  => EmailService::isConfigured(),
        'usage'  => $mailSent > 0 ? $mailSent . ' emails delivered' : 'No email delivered yet',
        'env'    => 'SMTP_HOST, SMTP_USERNAME, SMTP_PASSWORD, MAIL_FROM',
        'where'  => 'App/services/EmailService.php',
        'link'   => ['admin-settings-email', 'Email settings'],
    ],
    [
        'name'   => 'reCAPTCHA',
        'group'  => 'Authentication',
        'desc'   => 'Blocks automated sign-ups on the registration form.',
        'colour' => ['#A6301F', '#FBE5E1'],
        'ready'  => ($_ENV['RECAPTCHA_SITE_KEY'] ?? '') !== '' && ($_ENV['RECAPTCHA_SECRET_KEY'] ?? '') !== '',
        'usage'  => pc_setting_bool($con, 'captcha_enable') ? 'Enabled on the sign-up form' : 'Turned off in Security settings',
        'env'    => 'RECAPTCHA_SITE_KEY, RECAPTCHA_SECRET_KEY',
        'where'  => 'App/views/auth/signup.php',
        'link'   => ['admin-settings-security', 'Security settings'],
    ],
    [
        'name'   => 'Jitsi (JaaS) video',
        'group'  => 'Communication',
        'desc'   => 'The video room a mentor and mentee meet in.',
        'colour' => ['#6B21A8', '#F3E8FF'],
        'ready'  => JAAS_APP_ID !== '' && is_file(JAAS_PRIVATE_KEY_PATH),
        'usage'  => $videoRooms > 0 ? $videoRooms . ' sessions could use a room' : 'No sessions booked yet',
        'env'    => 'JAAS_APP_ID, JAAS_API_KEY_ID, JAAS_PRIVATE_KEY_PATH',
        'where'  => 'App/views/VideoConferencing/room.php',
    ],
    [
        'name'   => 'Daily.co video',
        'group'  => 'Communication',
        'desc'   => 'The alternative video provider, used when a key is present.',
        'colour' => ['#B7791F', '#FEF6DC'],
        'ready'  => DAILY_API_KEY !== '',
        'usage'  => DAILY_API_KEY !== '' ? 'API key present' : 'Not in use — Jitsi handles video',
        'env'    => 'DAILY_API_KEY',
        'where'  => 'App/views/VideoConferencing/',
    ],
];

$ready = count(array_filter($integrations, fn($i) => $i['ready']));
$total = count($integrations);
$pct   = $total > 0 ? round($ready / $total * 100) : 0;

$groups = [];
foreach ($integrations as $i) $groups[$i['group']][] = $i;

$current_page = 'settings-integrations';
include 'layout.php';
include __DIR__ . '/includes/settings_ui.php';
?>

<?php st_header('admin-settings-integrations', 'Integrations',
    'The outside services this platform talks to, and whether each one is configured.'); ?>

<div class="ss-stats">
    <?php foreach ([
        ['Integrations', (string)$total, 'Built into this platform', '#EAF1FB', '#1A5C9A', 'chart'],
        ['Configured', (string)$ready, $ready === $total ? 'All of them' : ($total - $ready) . ' need keys in .env', '#E6F5EE', '#17654B', 'check'],
        ['Google links', number_format($gcalLinked), 'Calendars connected by members', '#EAF6FB', '#0087CF', 'cal'],
        ['Emails delivered', number_format($mailSent), 'Through your SMTP server', '#FEF6DC', '#B7791F', 'chat'],
    ] as [$k, $v, $s, $bg, $fg, $ico]): ?>
        <div class="ss-stat">
            <span class="ss-stat-ico" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= ss_icon($ico) ?></span>
            <div style="min-width:0;">
                <div class="ss-stat-k"><?= $k ?></div>
                <div class="ss-stat-v"><?= $v ?></div>
                <div class="ss-stat-s"><?= htmlspecialchars($s) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="st-grid">
    <div class="st-stack">
        <?php foreach ($groups as $groupName => $items): ?>
            <div class="st-card">
                <h2><?= htmlspecialchars($groupName) ?></h2>
                <p class="sub"><?= count($items) ?> integration<?= count($items) === 1 ? '' : 's' ?></p>

                <?php foreach ($items as $i): [$fg, $bg] = $i['colour']; ?>
                    <div style="display:flex;align-items:flex-start;gap:13px;padding:15px 0;border-top:1px solid var(--gray-100);">
                        <span class="st-dot" style="width:42px;height:42px;border-radius:12px;background:<?= $bg ?>;color:<?= $fg ?>;">
                            <?= ss_icon($i['ready'] ? 'check' : 'x') ?>
                        </span>
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap;">
                                <b style="font-size:14px;font-weight:700;color:var(--forest);"><?= htmlspecialchars($i['name']) ?></b>
                                <span class="st-badge" style="background:<?= $i['ready'] ? '#E6F5EE' : '#F3F4F6' ?>;color:<?= $i['ready'] ? '#17654B' : '#565B66' ?>;">
                                    <?= $i['ready'] ? 'Configured' : 'Not configured' ?>
                                </span>
                            </div>
                            <p style="margin:4px 0 0;font-size:12.5px;color:var(--gray-500);line-height:1.55;"><?= htmlspecialchars($i['desc']) ?></p>
                            <p style="margin:6px 0 0;font-size:11.5px;color:var(--gray-400);">
                                <?= htmlspecialchars($i['usage']) ?>
                                &middot; keys: <code style="font-size:11px;"><?= htmlspecialchars($i['env']) ?></code>
                            </p>
                            <p style="margin:3px 0 0;font-size:11.5px;color:var(--gray-400);">
                                Code: <code style="font-size:11px;"><?= htmlspecialchars($i['where']) ?></code>
                                <?php if (!empty($i['link'])): ?>
                                    &middot; <a href="<?= url($i['link'][0]) ?>" style="color:var(--mint);text-decoration:none;font-weight:600;"><?= htmlspecialchars($i['link'][1]) ?> →</a>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ══════════ Side ══════════ -->
    <div class="st-stack">
        <div class="st-card">
            <h2>Integration health</h2>
            <?php $R = 44; $C = 2 * M_PI * $R; $len = $C * ($pct / 100); ?>
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <svg width="116" height="116" viewBox="0 0 116 116" style="flex:none;" role="img" aria-label="<?= $pct ?> percent configured">
                    <circle cx="58" cy="58" r="<?= $R ?>" fill="none" stroke="#EDEDED" stroke-width="12" />
                    <circle cx="58" cy="58" r="<?= $R ?>" fill="none" stroke="#17654B" stroke-width="12" stroke-linecap="round"
                            stroke-dasharray="<?= round($len, 2) ?> <?= round($C - $len, 2) ?>" transform="rotate(-90 58 58)" />
                    <text x="58" y="58" text-anchor="middle" font-size="19" font-weight="700" fill="#020547"><?= $pct ?>%</text>
                    <text x="58" y="72" text-anchor="middle" font-size="9" fill="#9A9EA6">ready</text>
                </svg>
                <div style="flex:1;min-width:120px;display:flex;flex-direction:column;gap:8px;font-size:12.5px;color:var(--gray-600);">
                    <div style="display:flex;align-items:center;gap:8px;"><i style="width:9px;height:9px;border-radius:50%;background:#17654B;"></i>Configured<b style="margin-left:auto;color:var(--forest);"><?= $ready ?></b></div>
                    <div style="display:flex;align-items:center;gap:8px;"><i style="width:9px;height:9px;border-radius:50%;background:#EDEDED;"></i>Needs keys<b style="margin-left:auto;color:var(--forest);"><?= $total - $ready ?></b></div>
                </div>
            </div>
        </div>

        <div class="st-card">
            <h2>How to connect one</h2>
            <div class="st-note">
                <b>Through .env, not this page.</b>
                Every integration is configured by putting its keys in the <code>.env</code> file at the project
                root and restarting. There is no “Connect” button here on purpose: an API secret typed into a
                web form ends up in the database and in every backup of it. This page tells you which keys are
                missing; the file is where they go.
            </div>
        </div>

        <div class="st-card">
            <h2>Not built</h2>
            <p class="sub">On the reference design, with no code in this app. Cards for them would claim connections that cannot exist.</p>
            <?php foreach (['Microsoft 365', 'Twilio SMS', 'Zoom', 'Dropbox', 'OneDrive', 'Slack', 'OpenAI', 'Google Analytics'] as $n): ?>
                <div class="st-row">
                    <span class="st-dot" style="background:#F3F4F6;color:#9A9EA6;"><?= ss_icon('x') ?></span>
                    <span style="min-width:0;flex:1;"><b style="color:var(--gray-500);"><?= $n ?></b><span class="h">No integration code exists</span></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
