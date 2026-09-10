<?php

/**
 * admin/settings.php — System Settings → General.
 *
 * Every switch on this page changes something. The rule the whole settings
 * area follows: if a control is drawn, some code path reads the value behind
 * it, and this file names that path in a comment beside each one. Controls
 * the app cannot honour are not drawn — a settings screen full of switches
 * that do nothing is worse than no settings screen.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/settings_store.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$S = pc_settings($con, true);

$one = function (string $sql) use ($con) {
    $r = $con->query($sql);
    return $r ? $r->fetch_row()[0] : null;
};

/* ── Live platform figures ────────────────────────────────────────────── */
$userCount   = (int)$one("SELECT COUNT(*) FROM users");
$memberCount = (int)$one("SELECT COUNT(*) FROM users WHERE role IN ('mentee','mentor')");
$sessionN    = (int)$one("SELECT COUNT(*) FROM session_requests");
$lastSignIn  = $one("SELECT MAX(log_date) FROM logs");

/* Storage actually used by uploads — a real figure, from the filesystem. */
function st_dir_size(string $dir): int
{
    if (!is_dir($dir)) return 0;
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) $n += $f->getSize();
    }
    return $n;
}
$uploadBytes = st_dir_size(PUBLIC_PATH . '/uploads');
$uploadLabel = $uploadBytes >= 1073741824
    ? round($uploadBytes / 1073741824, 2) . ' GB'
    : ($uploadBytes >= 1048576 ? round($uploadBytes / 1048576, 1) . ' MB' : round($uploadBytes / 1024) . ' KB');

/* Database size, from information_schema. */
$dbBytes = (int)($one("
    SELECT COALESCE(SUM(data_length + index_length), 0)
    FROM information_schema.TABLES WHERE table_schema = DATABASE()
") ?? 0);
$dbLabel = $dbBytes >= 1048576 ? round($dbBytes / 1048576, 1) . ' MB' : round($dbBytes / 1024) . ' KB';

$csrf = csrf_token();
$current_page = 'settings';
include 'layout.php';
include __DIR__ . '/includes/settings_ui.php';
?>

<?php st_header('admin-settings', 'System Settings',
    'Configure and manage the platform. Everything here changes how PeerConnect behaves.'); ?>

<!-- ══════════ Figures ══════════ -->
<div class="ss-stats">
    <?php foreach ([
        ['Platform status', pc_setting_bool($con, 'maintenance_mode') ? 'Maintenance' : 'Online',
         pc_setting_bool($con, 'maintenance_mode') ? 'Members are locked out' : 'Open to everyone',
         pc_setting_bool($con, 'maintenance_mode') ? '#FBEDDD' : '#E6F5EE',
         pc_setting_bool($con, 'maintenance_mode') ? '#9A4A00' : '#17654B', 'check'],
        ['Accounts', number_format($userCount), $memberCount . ' mentors and mentees', '#EAF1FB', '#1A5C9A', 'cal'],
        ['Uploads on disk', $uploadLabel, 'Database is ' . $dbLabel, '#EAF6FB', '#0087CF', 'chart'],
        ['Last sign-in', $lastSignIn ? date('M j, g:i A', strtotime($lastSignIn)) : '—', 'From the activity log', '#FEF6DC', '#B7791F', 'clock'],
    ] as [$k, $v, $s, $bg, $fg, $ico]): ?>
        <div class="ss-stat">
            <span class="ss-stat-ico" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= ss_icon($ico) ?></span>
            <div style="min-width:0;">
                <div class="ss-stat-k"><?= $k ?></div>
                <div class="ss-stat-v" style="font-size:<?= strlen($v) > 8 ? '18px' : '25px' ?>;"><?= htmlspecialchars($v) ?></div>
                <div class="ss-stat-s"><?= htmlspecialchars($s) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="st-grid">
    <div class="st-stack">
        <div class="st-cols">
            <!-- ── Platform information ── -->
            <form class="st-card" method="post" action="<?= url('admin-action-settings') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="section" value="platform">
                <h2>Platform information</h2>
                <p class="sub">The name and contact details shown to members.</p>

                <div class="st-f">
                    <label for="g-name">Platform name</label>
                    <input id="g-name" type="text" name="platform_name" maxlength="60" required value="<?= htmlspecialchars($S['platform_name']) ?>">
                    <small>Used in page titles, emails and the maintenance page.</small>
                </div>
                <div class="st-f">
                    <label for="g-tag">Tagline</label>
                    <input id="g-tag" type="text" name="platform_tagline" maxlength="120" value="<?= htmlspecialchars($S['platform_tagline']) ?>">
                </div>
                <div class="st-f">
                    <label for="g-desc">Description</label>
                    <textarea id="g-desc" name="platform_description" rows="3" maxlength="500" placeholder="What PeerConnect is, in a sentence or two."><?= htmlspecialchars($S['platform_description']) ?></textarea>
                </div>
                <div class="st-f">
                    <label for="g-url">Site URL</label>
                    <input id="g-url" type="url" name="site_url" maxlength="200" placeholder="<?= htmlspecialchars(APP_URL) ?>" value="<?= htmlspecialchars($S['site_url']) ?>">
                    <small>Leave empty to use <?= htmlspecialchars(APP_URL) ?>, which comes from .env.</small>
                </div>
                <div class="st-f">
                    <label for="g-support">Support email</label>
                    <input id="g-support" type="email" name="support_email" maxlength="120" value="<?= htmlspecialchars($S['support_email']) ?>">
                    <small>Shown to members who need help. Not the address mail is sent from — that is in .env.</small>
                </div>

                <div class="st-foot">
                    <button type="submit" class="st-save">
                        <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5 10 17 19 7" /></svg>
                        Save changes
                    </button>
                </div>
            </form>

            <!-- ── How the platform behaves ── -->
            <form class="st-card" method="post" action="<?= url('admin-action-settings') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="section" value="behaviour">
                <h2>System preferences</h2>
                <p class="sub">What people can do, and how much of it.</p>

                <?php
                // auth/signup.php — checked on the form and again on the POST.
                st_toggle('allow_registration', 'Allow new registrations',
                    'When off, the sign-up form is closed and new accounts cannot be created.',
                    $S['allow_registration'] === '1');

                // mentorpage/verification.php — see the note under it.
                st_toggle('auto_approve_mentors', 'Auto-approve mentor applications',
                    'Approve mentor applications the moment they are submitted, with no admin review.',
                    $S['auto_approve_mentors'] === '1', false,
                    'Leaves ID and credential checks unread.');

                // The member dashboards use this to decide whether to nag.
                st_toggle('require_verification', 'Require verification to book',
                    'Mentees are prompted to verify before booking their first session.',
                    $S['require_verification'] === '1');
                ?>

                <div class="st-f" style="margin-top:14px;">
                    <label for="g-role">Default role for a new account</label>
                    <select id="g-role" name="default_role">
                        <option value="mentee" <?= $S['default_role'] === 'mentee' ? 'selected' : '' ?>>Mentee</option>
                        <option value="mentor" <?= $S['default_role'] === 'mentor' ? 'selected' : '' ?>>Mentor</option>
                    </select>
                    <small>Applies when a sign-up does not state one — a Google sign-up, for instance.</small>
                </div>

                <div class="st-f">
                    <label for="g-cap">Session booking limit</label>
                    <select id="g-cap" name="booking_limit_week">
                        <option value="0" <?= $S['booking_limit_week'] === '0' ? 'selected' : '' ?>>No limit</option>
                        <?php foreach ([1, 2, 3, 5, 7, 10] as $n): ?>
                            <option value="<?= $n ?>" <?= (int)$S['booking_limit_week'] === $n ? 'selected' : '' ?>><?= $n ?> per week</option>
                        <?php endforeach; ?>
                    </select>
                    <small>Counted over live bookings in the last seven days. A cancelled session does not use up an allowance.</small>
                </div>

                <div class="st-foot">
                    <button type="submit" class="st-save">
                        <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5 10 17 19 7" /></svg>
                        Save changes
                    </button>
                </div>
            </form>
        </div>

        <!-- ── Maintenance ── -->
        <form class="st-card" method="post" action="<?= url('admin-action-settings') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="section" value="maintenance">
            <h2>Maintenance mode</h2>
            <p class="sub">Close the platform to members while you work on it.</p>

            <div class="st-note">
                <b>Admins are never locked out.</b>
                While maintenance is on, admin accounts keep full access and the sign-in pages stay open,
                so you can always get back in to turn it off. Everyone else sees your message with a 503,
                which tells search engines the outage is temporary.
            </div>

            <?php st_toggle('maintenance_mode', 'Enable maintenance mode',
                'Members see the message below instead of the app.',
                $S['maintenance_mode'] === '1'); ?>

            <div class="st-f" style="margin-top:14px;">
                <label for="g-msg">What members are told</label>
                <textarea id="g-msg" name="maintenance_message" rows="2" maxlength="300"><?= htmlspecialchars($S['maintenance_message']) ?></textarea>
            </div>

            <div class="st-foot">
                <button type="submit" class="st-save">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5 10 17 19 7" /></svg>
                    Save changes
                </button>
            </div>
        </form>
    </div>

    <!-- ══════════ Side ══════════ -->
    <div class="st-stack">
        <div class="st-card">
            <h2>System health</h2>
            <p class="sub">Checked live, each time this page loads.</p>
            <?php
            $mailOn = class_exists('EmailService') || is_file(BASE_PATH . '/App/services/EmailService.php');
            if ($mailOn) require_once BASE_PATH . '/App/services/EmailService.php';
            $checks = [
                ['Database', true, 'Connected as ' . htmlspecialchars($con->query('SELECT DATABASE()')->fetch_row()[0] ?? '?')],
                ['File uploads', is_writable(PUBLIC_PATH . '/uploads'), is_writable(PUBLIC_PATH . '/uploads') ? $uploadLabel . ' stored' : 'public/uploads is not writable'],
                ['Email (SMTP)', $mailOn && EmailService::isConfigured(), $mailOn && EmailService::isConfigured() ? 'Configured in .env' : 'SMTP_* missing from .env'],
                ['Google OAuth', ($_ENV['GOOGLE_CLIENT_ID'] ?? '') !== '', ($_ENV['GOOGLE_CLIENT_ID'] ?? '') !== '' ? 'Client ID present' : 'Not configured'],
                ['reCAPTCHA', ($_ENV['RECAPTCHA_SITE_KEY'] ?? '') !== '', ($_ENV['RECAPTCHA_SITE_KEY'] ?? '') !== '' ? 'Site key present' : 'Not configured'],
            ];
            foreach ($checks as [$label, $ok, $detail]): ?>
                <div class="st-row">
                    <span class="st-dot" style="background:<?= $ok ? '#E6F5EE' : '#FBEDDD' ?>;color:<?= $ok ? '#17654B' : '#9A4A00' ?>;">
                        <?= $ok ? ss_icon('check') : ss_icon('x') ?>
                    </span>
                    <span style="min-width:0;flex:1;"><b><?= $label ?></b><span class="h"><?= $detail ?></span></span>
                    <span class="st-badge" style="background:<?= $ok ? '#E6F5EE' : '#F3F4F6' ?>;color:<?= $ok ? '#17654B' : '#565B66' ?>;">
                        <?= $ok ? 'OK' : 'Off' ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="st-card">
            <h2>Where the rest lives</h2>
            <p class="sub">Not everything belongs in a database row.</p>
            <div class="st-note">
                <b>Credentials stay in .env</b>
                SMTP passwords, OAuth secrets and reCAPTCHA keys are read from <code>.env</code> and are never
                stored here or editable through this form — so they cannot leak through a database backup.
                These pages report whether each one is configured.
            </div>
            <div class="st-row">
                <span class="st-dot" style="background:#EAF1FB;color:#1A5C9A;"><?= ss_icon('clock') ?></span>
                <span style="min-width:0;flex:1;"><b>Timezone</b><span class="h">Asia/Manila, set per page in code</span></span>
            </div>
            <div class="st-row">
                <span class="st-dot" style="background:#EAF1FB;color:#1A5C9A;"><?= ss_icon('cal') ?></span>
                <span style="min-width:0;flex:1;"><b>Session length</b><span class="h"><?= PC_SESSION_DAYS ?> days, from Framework/bootstrap.php</span></span>
            </div>
        </div>

        <div class="st-card">
            <h2>Jump to</h2>
            <?php foreach ([
                ['admin-settings-security', 'Security', 'Lockout, passwords, CAPTCHA', '#FBE5E1', '#A6301F', 'x'],
                ['admin-settings-email', 'Email & notifications', 'What gets emailed, and to whom', '#EAF1FB', '#1A5C9A', 'chat'],
                ['admin-settings-integrations', 'Integrations', 'Google, mail, video, reCAPTCHA', '#E6F5EE', '#17654B', 'check'],
                ['admin-settings-logs', 'Activity logs', 'Who signed in, and when', '#FEF6DC', '#B7791F', 'chart'],
            ] as [$r, $t, $d, $bg, $fg, $ic]): ?>
                <a class="st-row" style="text-decoration:none;" href="<?= url($r) ?>">
                    <span class="st-dot" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= ss_icon($ic) ?></span>
                    <span style="min-width:0;flex:1;"><b><?= $t ?></b><span class="h"><?= $d ?></span></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
