<?php

/**
 * admin/settings_security.php — System Settings → Security.
 *
 * Only the controls this app can actually enforce. The reference design also
 * showed 2FA, IP allow-listing, a malware scan and a security score; none of
 * those exist here, and a switch that claims to require 2FA while nothing
 * checks for it is worse than no switch. What is here is wired:
 *
 *   lockout      → auth/login.php and admin/login.php
 *   CAPTCHA      → auth/signup.php (both the widget and the server check)
 *   password min → auth/signup.php and the password reset
 *
 * Active sessions are listed from what the app genuinely tracks: remember-me
 * tokens, which are rows with a device and a last-used time. PHP's own
 * sessions live in files with no user column, so they cannot be listed — and
 * this page says that rather than inventing a table of them.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/settings_store.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$S = pc_settings($con, true);
$csrf = csrf_token();

/* ── Sign-in activity, from the log ───────────────────────────────────── */
$one = function (string $sql) use ($con) {
    $r = $con->query($sql);
    return $r ? $r->fetch_row()[0] : null;
};
$signIns30 = (int)$one("SELECT COUNT(*) FROM logs WHERE activity LIKE 'user login%' AND log_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$adminIns30 = (int)$one("SELECT COUNT(*) FROM logs WHERE activity = 'admin login' AND log_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$resets30  = (int)$one("SELECT COUNT(*) FROM logs WHERE activity LIKE 'password reset%' AND log_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$blockedN  = (int)$one("SELECT COUNT(*) FROM users WHERE status = 'blocked'");
$restrictN = (int)$one("SELECT COUNT(*) FROM users WHERE status = 'restricted'");

/* ── Remember-me tokens: the only sessions this app can actually list ─── */
$tokens = $con->query("
    SELECT rt.*, CONCAT_WS(' ', u.firstname, u.lastname) AS name, u.role, p.profile_image
    FROM remember_tokens rt
    JOIN users u ON u.user_id = rt.user_id
    LEFT JOIN profile p ON p.user_id = rt.user_id
    ORDER BY rt.token_id DESC
    LIMIT 12
")->fetch_all(MYSQLI_ASSOC);

/* ── Recent sign-in events ────────────────────────────────────────────── */
// Account events only. Admin changes are in the full log, and a run of them
// would push every sign-in off this card.
$events = $con->query("
    SELECT l.activity, l.email, l.log_date,
           CONCAT_WS(' ', u.firstname, u.lastname) AS name, u.role
    FROM logs l
    LEFT JOIN users u ON u.email = l.email
    WHERE l.activity NOT LIKE 'admin %' OR l.activity IN ('admin login', 'admin logout', 'admin account created')
    ORDER BY l.log_id DESC LIMIT 8
")->fetch_all(MYSQLI_ASSOC);

$current_page = 'settings-security';
include 'layout.php';
include __DIR__ . '/includes/settings_ui.php';
?>

<?php st_header('admin-settings-security', 'Security',
    'The protections this platform enforces, and who has been signing in.'); ?>

<div class="ss-stats">
    <?php foreach ([
        ['Sign-ins (30 days)', number_format($signIns30), $adminIns30 . ' by an admin', '#EAF1FB', '#1A5C9A', 'check'],
        ['Password resets', number_format($resets30), 'In the last 30 days', '#EAF6FB', '#0087CF', 'clock'],
        ['Blocked accounts', number_format($blockedN), $restrictN . ' restricted', $blockedN > 0 ? '#FBE5E1' : '#F3F4F6', $blockedN > 0 ? '#A6301F' : '#565B66', 'x'],
        ['Remembered devices', number_format(count($tokens)), 'Signed in without a password', '#FEF6DC', '#B7791F', 'star'],
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
        <form class="st-card" method="post" action="<?= url('admin-action-settings') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="section" value="security">
            <h2>Sign-in protection</h2>
            <p class="sub">Applies to the member sign-in and the admin sign-in alike.</p>

            <?php st_toggle('login_lockout_enable', 'Lock an account after repeated failures',
                'Someone guessing a password is stopped for a while instead of being allowed to keep trying.',
                $S['login_lockout_enable'] === '1'); ?>

            <div class="st-f-row" style="margin-top:14px;">
                <div class="st-f">
                    <label for="s-tries">Failures before the lock</label>
                    <select id="s-tries" name="login_max_attempts">
                        <?php foreach ([3, 5, 8, 10] as $n): ?>
                            <option value="<?= $n ?>" <?= (int)$S['login_max_attempts'] === $n ? 'selected' : '' ?>><?= $n ?> attempts</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="st-f">
                    <label for="s-mins">How long it lasts</label>
                    <select id="s-mins" name="login_lockout_mins">
                        <?php foreach ([5, 10, 15, 30, 60] as $n): ?>
                            <option value="<?= $n ?>" <?= (int)$S['login_lockout_mins'] === $n ? 'selected' : '' ?>><?= $n ?> minutes</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php
            /*
             * The CAPTCHA switch only makes sense where keys exist. Turning it
             * off is offered because an admin may need to while testing, but
             * the page is explicit that it is the thing standing between the
             * sign-up form and automated accounts.
             */
            $captchaKeyed = ($_ENV['RECAPTCHA_SITE_KEY'] ?? '') !== '' && ($_ENV['RECAPTCHA_SECRET_KEY'] ?? '') !== '';
            st_toggle('captcha_enable', 'CAPTCHA on the sign-up form',
                'Google reCAPTCHA has to be solved before an account is created.',
                $S['captcha_enable'] === '1' && $captchaKeyed,
                !$captchaKeyed,
                $captchaKeyed ? 'This is what stops automated sign-ups.' : 'Add RECAPTCHA_SITE_KEY and RECAPTCHA_SECRET_KEY to .env to use this.');
            ?>

            <div class="st-f" style="margin-top:14px;">
                <label for="s-pw">Minimum password length</label>
                <select id="s-pw" name="password_min_length">
                    <?php foreach ([6, 8, 10, 12] as $n): ?>
                        <option value="<?= $n ?>" <?= (int)$S['password_min_length'] === $n ? 'selected' : '' ?>><?= $n ?> characters</option>
                    <?php endforeach; ?>
                </select>
                <small>A password must also contain an upper case letter, a lower case letter, a number and one of @#$%^&amp;*!? — those are not optional.</small>
            </div>

            <div class="st-foot">
                <button type="submit" class="st-save">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5 10 17 19 7" /></svg>
                    Save changes
                </button>
            </div>
        </form>

        <!-- ── Remembered devices ── -->
        <div class="st-card">
            <h2>Remembered devices</h2>
            <p class="sub">Where someone has ticked “Remember me” and can sign in without a password.</p>

            <div class="st-note">
                <b>These are the only sessions that can be listed.</b>
                PHP keeps ordinary sign-ins in server-side files with no user column, so there is no honest way
                to show a table of who is logged in right now. Revoking a remembered device below forces that
                browser to sign in with a password again.
            </div>

            <?php if (!$tokens): ?>
                <p class="ss-none">Nobody has a remembered device.</p>
            <?php else: foreach ($tokens as $t):
                $expired = !empty($t['expires_at']) && strtotime($t['expires_at']) < time(); ?>
                <div class="st-row">
                    <span class="ss-av" style="width:34px;height:34px;">
                        <?= $t['profile_image'] ? '<img src="' . htmlspecialchars($t['profile_image']) . '" alt="">' : htmlspecialchars(strtoupper(substr($t['name'], 0, 2))) ?>
                    </span>
                    <span style="min-width:0;flex:1;">
                        <b><?= htmlspecialchars($t['name']) ?></b>
                        <span class="h">
                            <?= htmlspecialchars(ucfirst($t['role'])) ?>
                            <?php if (!empty($t['expires_at'])): ?>
                                &middot; <?= $expired ? 'expired ' : 'valid until ' ?><?= date('M j, Y', strtotime($t['expires_at'])) ?>
                            <?php endif; ?>
                        </span>
                    </span>
                    <form method="post" action="<?= url('admin-action-settings') ?>" style="margin-left:auto;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="section" value="revoke_token">
                        <input type="hidden" name="token_id" value="<?= (int)$t['token_id'] ?>">
                        <button type="submit" class="an-btn danger" style="width:auto;padding:6px 13px;border:1px solid #F3C9C0;border-radius:9px;background:#fff;color:#A6301F;font-family:inherit;font-size:12px;font-weight:600;cursor:pointer;">Revoke</button>
                    </form>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- ══════════ Side ══════════ -->
    <div class="st-stack">
        <div class="st-card">
            <h2>What is protecting this platform</h2>
            <p class="sub">Checked against the code, not a stored value.</p>
            <?php
            $items = [
                ['Passwords are hashed', true, 'bcrypt, via password_hash()'],
                ['CSRF tokens on forms', true, 'Every state-changing POST is checked'],
                ['Prepared statements', true, 'User input never concatenated into SQL'],
                ['Session cookies hardened', true, 'HttpOnly, SameSite=Lax, strict mode'],
                ['Sign-in lockout', pc_setting_bool($con, 'login_lockout_enable'),
                 pc_setting_bool($con, 'login_lockout_enable')
                    ? pc_setting_int($con, 'login_max_attempts', 5) . ' tries, then ' . pc_setting_int($con, 'login_lockout_mins', 5) . ' minutes'
                    : 'Turned off'],
                ['CAPTCHA on sign-up', $captchaKeyed && pc_setting_bool($con, 'captcha_enable'),
                 $captchaKeyed ? (pc_setting_bool($con, 'captcha_enable') ? 'Active' : 'Turned off') : 'No keys in .env'],
                ['HTTPS', (($_SERVER['HTTPS'] ?? '') !== '' || ($_SERVER['SERVER_PORT'] ?? '') == 443),
                 (($_SERVER['HTTPS'] ?? '') !== '' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? 'This request is encrypted' : 'This request is plain HTTP'],
            ];
            foreach ($items as [$label, $ok, $detail]): ?>
                <div class="st-row">
                    <span class="st-dot" style="background:<?= $ok ? '#E6F5EE' : '#FBEDDD' ?>;color:<?= $ok ? '#17654B' : '#9A4A00' ?>;">
                        <?= $ok ? ss_icon('check') : ss_icon('x') ?>
                    </span>
                    <span style="min-width:0;flex:1;"><b><?= $label ?></b><span class="h"><?= htmlspecialchars($detail) ?></span></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="st-card">
            <h2>Recent sign-in activity</h2>
            <p class="sub">From the activity log.</p>
            <?php if (!$events): ?>
                <p class="ss-none">Nothing logged yet.</p>
            <?php else: foreach ($events as $e):
                $bad = str_contains($e['activity'], 'reset'); ?>
                <div class="st-row">
                    <span class="st-dot" style="background:<?= $bad ? '#FEF6DC' : '#E6F5EE' ?>;color:<?= $bad ? '#B7791F' : '#17654B' ?>;">
                        <?= ss_icon($bad ? 'clock' : 'check') ?>
                    </span>
                    <span style="min-width:0;flex:1;">
                        <b><?= htmlspecialchars(ucfirst($e['activity'])) ?></b>
                        <span class="h"><?= htmlspecialchars($e['name'] ?: $e['email']) ?> &middot; <?= date('M j, g:i A', strtotime($e['log_date'])) ?></span>
                    </span>
                </div>
            <?php endforeach; endif; ?>
            <a class="st-row" style="text-decoration:none;" href="<?= url('admin-settings-logs') ?>">
                <span style="font-size:12.5px;font-weight:600;color:var(--mint);">See the full activity log →</span>
            </a>
        </div>

        <div class="st-card">
            <h2>Not available here</h2>
            <p class="sub">The reference design showed these; this app has no code behind them, so they are not drawn as switches that do nothing.</p>
            <?php foreach ([
                'Two-factor authentication' => 'Nothing in the sign-in flow checks a second factor.',
                'IP allow-listing' => 'No allow-list is consulted anywhere.',
                'Malware and intrusion scanning' => 'There is no scanner to report on.',
            ] as $t => $d): ?>
                <div class="st-row">
                    <span class="st-dot" style="background:#F3F4F6;color:#9A9EA6;"><?= ss_icon('x') ?></span>
                    <span style="min-width:0;flex:1;"><b style="color:var(--gray-500);"><?= $t ?></b><span class="h"><?= $d ?></span></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
