<?php

/**
 * admin/profile.php — an administrator's own profile.
 *
 * What an administrator proved when they were approved: their name, their
 * student ID, their course and year, and the post they hold in their club.
 * That application was read once by the owner and then never seen again;
 * this is where it lives afterwards.
 *
 * WHAT IS NOT HERE, AND WHY
 * The reference for this page showed a language picker, a time-zone picker
 * and a per-person email-notifications switch. None of those exist: the site
 * is in one language, its clock is set once for everybody in the application,
 * and email is switched on or off for the whole site in System Settings. They
 * are left out rather than drawn as controls that would do nothing.
 *
 * Everything shown is read from the database on this request. The four
 * figures are counts, not estimates.
 *
 * SECURITY: an approved administrator, and they see only their own profile.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$current_page = 'admin-profile';
$user_id      = (int)$_SESSION['user_id'];
$acct    = UserRepository::namesAndPhoto($con, $user_id) ?: [];
$account = UserRepository::accountRow($con, $user_id) ?: [];

// The approved application, if there is one. An administrator created before
// this review existed has none, and the section simply does not appear.
$app = VerificationRepository::forUser($con, $user_id);
$app = ($app && ($app['status'] ?? '') === 'approved') ? $app : null;

$name  = trim(($acct['firstname'] ?? '') . ' ' . ($acct['lastname'] ?? '')) ?: 'Administrator';
$photo = $acct['profile_image'] ?? '';

$figures = AdminUserRepository::profileFigures($con);

$page_title = 'Admin Profile';
require_once __DIR__ . '/layout.php';
?>

<style>
    .ap-hero {
        display: flex;
        align-items: center;
        gap: 18px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 22px;
        margin-bottom: 18px;
    }

    .ap-av {
        width: 78px;
        height: 78px;
        border-radius: 50%;
        background: var(--accent-faint);
        color: var(--navy);
        display: grid;
        place-items: center;
        font-size: 24px;
        font-weight: 700;
        overflow: hidden;
        flex-shrink: 0;
    }

    .ap-av img { width: 100%; height: 100%; object-fit: cover; }

    .ap-name { font-size: 21px; font-weight: 700; color: var(--gray-900); }

    .ap-badge {
        display: inline-block;
        margin-left: 8px;
        padding: 3px 10px;
        border-radius: 999px;
        background: var(--accent-faint);
        color: var(--mint-deep);
        font-size: 11.5px;
        font-weight: 600;
        vertical-align: middle;
    }

    .ap-meta { font-size: 13px; color: var(--gray-500); margin-top: 5px; }

    .ap-stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 18px;
    }

    .ap-stat {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 18px;
    }

    .ap-stat b { display: block; font-size: 24px; color: var(--navy); line-height: 1.1; }
    .ap-stat span { font-size: 12.5px; color: var(--gray-500); }

    .ap-cols {
        display: grid;
        grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr);
        gap: 18px;
        align-items: start;
    }

    .ap-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 22px;
        margin-bottom: 18px;
    }

    .ap-card h2 {
        margin: 0 0 16px;
        font-size: 15px;
        font-weight: 700;
        color: var(--gray-900);
    }

    .ap-field { margin-bottom: 14px; }

    .ap-k {
        display: block;
        font-size: 11.5px;
        font-weight: 600;
        color: var(--gray-500);
        margin-bottom: 5px;
    }

    .ap-v {
        font-size: 13.5px;
        color: var(--gray-800);
        background: var(--gray-50);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 10px 12px;
        word-break: break-word;
    }

    .ap-input {
        width: 100%;
        font: inherit;
        font-size: 13.5px;
        padding: 10px 12px;
        border: 1px solid var(--border);
        border-radius: var(--radius);
    }

    .ap-msg { font-size: 12.5px; margin-top: 10px; display: none; }
    .ap-msg.ok { color: var(--success); display: block; }
    .ap-msg.no { color: var(--danger); display: block; }

    @media (max-width: 1000px) {
        .ap-cols { grid-template-columns: minmax(0, 1fr); }
        .ap-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width: 520px) {
        .ap-stats { grid-template-columns: minmax(0, 1fr); }
        .ap-hero { flex-direction: column; text-align: center; }
    }
</style>

<div class="ap-hero">
    <span class="ap-av" id="apAvatar"><?= pc_avatar($photo, $name) ?></span>
    <div style="min-width:0;">
        <div class="ap-name">
            <?= htmlspecialchars($name) ?><span class="ap-badge">Administrator</span>
        </div>
        <div class="ap-meta">
            <?= htmlspecialchars((string)($acct['email'] ?? '')) ?>
            <?php if (!empty($account['created_at'])): ?>
                &nbsp;·&nbsp; Joined <?= htmlspecialchars(date('F j, Y', strtotime((string)$account['created_at']))) ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="ap-stats">
    <div class="ap-stat"><b><?= number_format($figures['members']) ?></b><span>Mentors &amp; mentees</span></div>
    <div class="ap-stat"><b><?= number_format($figures['sessions']) ?></b><span>Sessions booked</span></div>
    <div class="ap-stat"><b><?= number_format($figures['reports']) ?></b><span>Reports awaiting review</span></div>
    <div class="ap-stat">
        <b style="color:<?= $figures['maintenance'] ? 'var(--gold)' : 'var(--success)' ?>;">
            <?= $figures['maintenance'] ? 'Maintenance' : 'Live' ?>
        </b>
        <span><?= $figures['maintenance'] ? 'Members are locked out' : 'All systems operational' ?></span>
    </div>
</div>

<div class="ap-cols">
    <div>
        <div class="ap-card">
            <h2>Account</h2>
            <div class="ap-field">
                <span class="ap-k">Full name</span>
                <div class="ap-v"><?= htmlspecialchars($name) ?></div>
            </div>
            <div class="ap-field">
                <span class="ap-k">Username</span>
                <div class="ap-v"><?= htmlspecialchars((string)($account['username'] ?? '—')) ?></div>
            </div>
            <div class="ap-field">
                <span class="ap-k">Email address</span>
                <div class="ap-v"><?= htmlspecialchars((string)($acct['email'] ?? '')) ?></div>
            </div>
            <div class="ap-field" style="margin-bottom:0;">
                <span class="ap-k">Role</span>
                <div class="ap-v">Administrator — full access</div>
            </div>
        </div>

        <?php if ($app !== null): ?>
            <div class="ap-card">
                <h2>Verified details</h2>
                <p style="margin:-8px 0 16px;font-size:12.5px;color:var(--gray-500);line-height:1.6;">
                    Checked against your ID and Certificate of Registration when this account was approved.
                </p>
                <div class="ap-field">
                    <span class="ap-k">Post</span>
                    <div class="ap-v"><?= htmlspecialchars((string)$app['club']) ?></div>
                </div>
                <div class="ap-field">
                    <span class="ap-k">Student ID</span>
                    <div class="ap-v"><?= htmlspecialchars((string)$app['student_id']) ?></div>
                </div>
                <div class="ap-field">
                    <span class="ap-k">Course</span>
                    <div class="ap-v"><?= htmlspecialchars((string)$app['course']) ?></div>
                </div>
                <div class="ap-field" style="margin-bottom:0;">
                    <span class="ap-k">Year level</span>
                    <div class="ap-v"><?= htmlspecialchars((string)$app['year_level']) ?></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="ap-card">
            <h2>Profile picture</h2>
            <form id="apPhoto" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input class="ap-input" type="file" name="profile_image" accept="image/jpeg,image/png,image/gif,image/webp" required>
                <p style="margin:8px 0 12px;font-size:11.5px;color:var(--gray-500);">JPG, PNG, GIF or WEBP, up to 5 MB.</p>
                <button class="btn btn-primary" type="submit">Save picture</button>
                <div class="ap-msg" id="apPhotoMsg" role="status"></div>
            </form>
        </div>

        <div class="ap-card">
            <h2>Change password</h2>
            <form id="apPw">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <div class="ap-field">
                    <span class="ap-k">Current password</span>
                    <input class="ap-input" type="password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="ap-field">
                    <span class="ap-k">New password</span>
                    <input class="ap-input" type="password" name="new_password" required autocomplete="new-password">
                </div>
                <div class="ap-field">
                    <span class="ap-k">Confirm new password</span>
                    <input class="ap-input" type="password" name="confirm_password" required autocomplete="new-password">
                </div>
                <button class="btn btn-primary" type="submit">Update password</button>
                <div class="ap-msg" id="apPwMsg" role="status"></div>
            </form>
        </div>
    </div>
</div>

<script>
    /* Both forms post to endpoints that already existed and already answer in
       JSON; nothing here decides anything, it only reports what they said. */
    function apSay(el, ok, text) {
        el.className = 'ap-msg ' + (ok ? 'ok' : 'no');
        el.textContent = text;
    }

    document.getElementById('apPhoto').addEventListener('submit', async function (e) {
        e.preventDefault();
        const msg = document.getElementById('apPhotoMsg');
        apSay(msg, true, 'Saving…');
        try {
            const res = await fetch(<?= json_encode(url('admin-update-photo')) ?>, {
                method: 'POST', body: new FormData(this)
            });
            const d = await res.json();
            apSay(msg, !!d.success, d.success ? 'Picture updated.' : (d.message || 'That did not work.'));
            if (d.success && d.image) {
                document.getElementById('apAvatar').innerHTML =
                    '<img src="' + d.image.replace(/"/g, '&quot;') + '" alt="">';
            }
        } catch (err) {
            apSay(msg, false, 'That did not work. Please try again.');
        }
    });

    document.getElementById('apPw').addEventListener('submit', async function (e) {
        e.preventDefault();
        const msg = document.getElementById('apPwMsg');
        apSay(msg, true, 'Updating…');
        try {
            const res = await fetch(<?= json_encode(url('account-update-password')) ?>, {
                method: 'POST', body: new FormData(this)
            });
            const d = await res.json();
            apSay(msg, !!d.success, d.message || (d.success ? 'Password updated.' : 'That did not work.'));
            if (d.success) { this.reset(); }
        } catch (err) {
            apSay(msg, false, 'That did not work. Please try again.');
        }
    });
</script>

<?php include __DIR__ . '/layout_end.php'; ?>
