<?php

/**
 * admin/settings_email.php — System Settings → Email & Notifications.
 *
 * SMTP credentials are shown but not editable: they live in .env, where a
 * database backup cannot carry them and a web form cannot change them. What
 * is editable is which notifications are also emailed — read by
 * NotificationService::emailAllowed() before every send.
 *
 * The delivery figures are real. Every notification row carries an
 * email_status of sent, failed or skipped, written when it was dispatched, so
 * the numbers here are counts of rows rather than an estimate.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/settings_store.php';
require_once __DIR__ . '/../../services/EmailService.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$S = pc_settings($con, true);
$csrf = csrf_token();
$configured = EmailService::isConfigured();

$one = function (string $sql) use ($con) {
    $r = $con->query($sql);
    return $r ? $r->fetch_row()[0] : null;
};

$sent30    = (int)$one("SELECT COUNT(*) FROM notifications WHERE email_status = 'sent' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$failed30  = (int)$one("SELECT COUNT(*) FROM notifications WHERE email_status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$skipped30 = (int)$one("SELECT COUNT(*) FROM notifications WHERE email_status = 'skipped' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$pending30 = (int)$one("SELECT COUNT(*) FROM notifications WHERE (email_status IS NULL OR email_status = 'pending') AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$inApp30   = (int)$one("SELECT COUNT(*) FROM notifications WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$attempted = $sent30 + $failed30;
$deliveryPct = $attempted > 0 ? round($sent30 / $attempted * 100, 1) : null;

$subscribers = (int)$one("SELECT COUNT(*) FROM users WHERE role IN ('mentee','mentor') AND status <> 'blocked'");

/* Which notification categories are actually in use, and how they went. */
$byType = $con->query("
    SELECT type, COUNT(*) n,
           SUM(email_status = 'sent') sent,
           SUM(email_status = 'failed') failed,
           SUM(email_status = 'skipped') skipped
    FROM notifications GROUP BY type ORDER BY n DESC LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

/* Anything that failed is worth an admin's eyes, with the reason attached. */
$failures = $con->query("
    SELECT n.notification_id, n.type, n.title, n.created_at, n.email_error,
           CONCAT_WS(' ', u.firstname, u.lastname) AS who
    FROM notifications n LEFT JOIN users u ON u.user_id = n.user_id
    WHERE n.email_status = 'failed'
    ORDER BY n.notification_id DESC LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

$current_page = 'settings-email';
include 'layout.php';
include __DIR__ . '/includes/settings_ui.php';
?>

<?php st_header('admin-settings-email', 'Email & Notifications',
    'How members are told about things, and how that delivery is going.'); ?>

<div class="ss-stats">
    <?php foreach ([
        ['Emails sent (30 days)', number_format($sent30), $attempted > 0 ? $failed30 . ' failed' : 'None attempted', '#EAF1FB', '#1A5C9A', 'check'],
        ['In-app notifications', number_format($inApp30), 'Delivered inside PeerConnect', '#EAF6FC', '#087FC1', 'chat'],
        ['Delivery rate', $deliveryPct !== null ? $deliveryPct . '%' : '—', $attempted > 0 ? 'Of ' . $attempted . ' attempted' : 'Nothing to measure yet', '#E6F5EE', '#17654B', 'star'],
        ['People reachable', number_format($subscribers), 'Unblocked members with an account', '#FEF6DC', '#B7791F', 'cal'],
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
        <div class="st-cols">
            <!-- ── SMTP, read-only ── -->
            <div class="st-card">
                <h2>Mail server</h2>
                <p class="sub">Read from .env. Not editable here, on purpose.</p>

                <div class="st-note<?= $configured ? '' : ' bad' ?>">
                    <b><?= $configured ? 'SMTP is configured.' : 'SMTP is not configured.' ?></b>
                    <?= $configured
                        ? 'Notifications are emailed as well as shown in the app.'
                        : 'Members still get in-app notifications; no email is sent until SMTP_HOST, SMTP_USERNAME, SMTP_PASSWORD and MAIL_FROM are set in .env.' ?>
                </div>

                <?php foreach ([
                    ['Host', SMTP_HOST ?: '—'],
                    ['Port', SMTP_PORT ?: '—'],
                    ['Username', SMTP_USERNAME !== '' ? SMTP_USERNAME : '—'],
                    ['Password', SMTP_PASSWORD !== '' ? str_repeat('•', 10) . ' (set in .env)' : '— not set'],
                    ['Sends as', MAIL_FROM !== '' ? MAIL_FROM_NAME . ' <' . MAIL_FROM . '>' : '—'],
                ] as [$k, $v]): ?>
                    <div class="st-row">
                        <span style="min-width:0;flex:1;"><b><?= $k ?></b><span class="h"><?= htmlspecialchars((string)$v) ?></span></span>
                    </div>
                <?php endforeach; ?>

                <form method="post" action="<?= url('admin-action-settings') ?>" style="margin-top:14px;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="section" value="test_email">
                    <div class="st-f">
                        <label for="e-to">Send a test to</label>
                        <input id="e-to" type="email" name="to" required placeholder="you@example.com" <?= $configured ? '' : 'disabled' ?>>
                        <small>Delivers a real email through the settings above, so you can see whether they work.</small>
                    </div>
                    <button type="submit" class="st-save" <?= $configured ? '' : 'disabled style="opacity:.5;cursor:not-allowed;"' ?>>
                        <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 12.5 20 5l-7 15-2.5-6.5L4 12.5Z" /></svg>
                        Send test email
                    </button>
                </form>
            </div>

            <!-- ── What gets emailed ── -->
            <form class="st-card" method="post" action="<?= url('admin-action-settings') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="section" value="email">
                <h2>What gets emailed</h2>
                <p class="sub">In-app notifications are unaffected — these switch off the email copy only.</p>

                <?php
                st_toggle('email_enable', 'Send notification emails at all',
                    'The master switch. Off means nothing is emailed, whatever the rest say.',
                    $S['email_enable'] === '1', !$configured,
                    $configured ? '' : 'SMTP is not configured, so nothing sends regardless.');

                foreach ([
                    ['email_on_registration', 'New account', 'Welcome mail when someone signs up.'],
                    ['email_on_booking', 'Session bookings', 'Requested, accepted, declined and cancelled.'],
                    ['email_on_reminder', 'Session reminders', 'Before a booked session starts.'],
                    ['email_on_message', 'New messages', 'When somebody writes to them.'],
                    ['email_on_assessment', 'Assessments and feedback', 'Submissions and ratings received.'],
                    ['email_on_announcement', 'Announcements', 'When an admin publishes one.'],
                ] as [$key, $label, $help]) {
                    st_toggle($key, $label, $help, $S[$key] === '1', !$configured);
                }
                ?>

                <div class="st-note" style="margin-top:14px;">
                    <b>Members can still opt out.</b>
                    These switches are the platform's ceiling; each person's own Settings → Notifications
                    can turn a category off for themselves. Verification and account actions always send,
                    because somebody blocked or approved needs to be told.
                </div>

                <div class="st-foot">
                    <button type="submit" class="st-save">
                        <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5 10 17 19 7" /></svg>
                        Save changes
                    </button>
                </div>
            </form>
        </div>

        <!-- ── Delivery by category ── -->
        <div class="st-card">
            <h2>Delivery by notification type</h2>
            <p class="sub">Every notification ever sent, and what happened to its email.</p>
            <?php if (!$byType): ?>
                <p class="ss-none">No notifications have been sent yet.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;">
                        <thead>
                            <tr style="text-align:left;color:var(--gray-400);font-size:11px;text-transform:uppercase;letter-spacing:.05em;">
                                <th style="padding:8px 10px 8px 0;font-weight:700;">Type</th>
                                <th style="padding:8px 10px;font-weight:700;text-align:right;">Total</th>
                                <th style="padding:8px 10px;font-weight:700;text-align:right;">Emailed</th>
                                <th style="padding:8px 10px;font-weight:700;text-align:right;">Failed</th>
                                <th style="padding:8px 0 8px 10px;font-weight:700;text-align:right;">Not emailed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($byType as $t): ?>
                                <tr style="border-top:1px solid var(--gray-100);">
                                    <td style="padding:10px 10px 10px 0;font-weight:600;color:var(--gray-800);"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $t['type']))) ?></td>
                                    <td style="padding:10px;text-align:right;font-variant-numeric:tabular-nums;"><?= (int)$t['n'] ?></td>
                                    <td style="padding:10px;text-align:right;font-variant-numeric:tabular-nums;color:#17654B;"><?= (int)$t['sent'] ?></td>
                                    <td style="padding:10px;text-align:right;font-variant-numeric:tabular-nums;color:<?= (int)$t['failed'] > 0 ? '#A6301F' : 'var(--gray-300)' ?>;"><?= (int)$t['failed'] ?></td>
                                    <td style="padding:10px 0 10px 10px;text-align:right;font-variant-numeric:tabular-nums;color:var(--gray-400);"><?= (int)$t['skipped'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══════════ Side ══════════ -->
    <div class="st-stack">
        <div class="st-card">
            <h2>Delivery</h2>
            <?php if ($attempted === 0 && $skipped30 === 0 && $pending30 === 0): ?>
                <p class="ss-none">No email has been attempted in the last 30 days.</p>
            <?php else:
                $R = 44; $C = 2 * M_PI * $R;
                $pct = $deliveryPct ?? 0;
                $len = $C * ($pct / 100); ?>
                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                    <svg width="116" height="116" viewBox="0 0 116 116" style="flex:none;" role="img" aria-label="<?= $pct ?> percent delivered">
                        <circle cx="58" cy="58" r="<?= $R ?>" fill="none" stroke="#EDEDED" stroke-width="12" />
                        <circle cx="58" cy="58" r="<?= $R ?>" fill="none" stroke="#1B6FD1" stroke-width="12" stroke-linecap="round"
                                stroke-dasharray="<?= round($len, 2) ?> <?= round($C - $len, 2) ?>" transform="rotate(-90 58 58)" />
                        <text x="58" y="58" text-anchor="middle" font-size="19" font-weight="700" fill="#071B4D"><?= $pct ?>%</text>
                        <text x="58" y="72" text-anchor="middle" font-size="9" fill="#9A9EA6">delivered</text>
                    </svg>
                    <div style="flex:1;min-width:130px;display:flex;flex-direction:column;gap:8px;font-size:12.5px;color:var(--gray-600);">
                        <div style="display:flex;align-items:center;gap:8px;"><i style="width:9px;height:9px;border-radius:50%;background:#1B6FD1;"></i>Sent<b style="margin-left:auto;color:var(--forest);"><?= $sent30 ?></b></div>
                        <div style="display:flex;align-items:center;gap:8px;"><i style="width:9px;height:9px;border-radius:50%;background:#C0392B;"></i>Failed<b style="margin-left:auto;color:var(--forest);"><?= $failed30 ?></b></div>
                        <div style="display:flex;align-items:center;gap:8px;"><i style="width:9px;height:9px;border-radius:50%;background:#EDEDED;"></i>Not emailed<b style="margin-left:auto;color:var(--forest);"><?= $skipped30 ?></b></div>
                        <?php if ($pending30 > 0): ?>
                            <div style="display:flex;align-items:center;gap:8px;"><i style="width:9px;height:9px;border-radius:50%;background:#B7791F;"></i>Pending<b style="margin-left:auto;color:var(--forest);"><?= $pending30 ?></b></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="st-card">
            <h2>Failed deliveries</h2>
            <p class="sub">With the reason the mail server gave.</p>
            <?php if (!$failures): ?>
                <p class="ss-none">Nothing has failed to send.</p>
            <?php else: foreach ($failures as $f): ?>
                <div class="st-row">
                    <span class="st-dot" style="background:#FBE5E1;color:#A6301F;"><?= ss_icon('x') ?></span>
                    <span style="min-width:0;flex:1;">
                        <b><?= htmlspecialchars($f['title']) ?></b>
                        <span class="h">
                            To <?= htmlspecialchars($f['who'] ?: 'a removed account') ?> &middot; <?= date('M j, g:i A', strtotime($f['created_at'])) ?>
                        </span>
                        <?php if (trim((string)$f['email_error']) !== ''): ?>
                            <span class="h" style="color:#A6301F;"><?= htmlspecialchars($f['email_error']) ?></span>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="st-card">
            <h2>Email templates</h2>
            <p class="sub">Where the layout of every mail comes from.</p>
            <div class="st-note">
                <b>Templates live in code.</b>
                Every notification email is rendered by <code>EmailService::notificationTemplate()</code>,
                which takes the title, the message and an optional link. There is no per-template row to edit,
                so no template editor is drawn here — the reference design's list would have been a table of
                things that do not exist. Changing the wording of a specific email means changing the call
                that creates it, in <code>NotificationService</code>.
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/layout_end.php'; ?>
