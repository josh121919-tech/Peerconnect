<?php

/**
 * admin/user_view.php — one account, everything the admin holds on it.
 *
 * The user list answers "who is on the platform"; this answers "who is this
 * person, and should I act on them". Mentors have a public profile page and
 * mentees do not, so an admin who wanted to check a mentee previously had
 * nowhere to look. This is the admin's own view rather than the public one:
 * it shows the verification submission, the moderation history and the
 * activity record, none of which belong on a page members can see.
 *
 * Every figure is read live from the same tables the member's own pages use,
 * so a number here always matches what that person sees. Sections with no
 * data say so rather than being hidden, because "no reports" is itself an
 * answer an admin came here for.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/moderation_helpers.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$me  = (int)($_SESSION['user_id'] ?? 0);
$uid = is_string($_GET['id'] ?? null) ? (int)$_GET['id'] : 0;

/* ── The account ──────────────────────────────────────────────────────── */
$u = AdminUserRepository::account($con, $uid);

if (!$u) {
    pc_flash('error', 'That account no longer exists.');
    header('Location: ' . url('admin-users'));
    exit;
}

$name  = trim(preg_replace('/\s+/', ' ', $u['firstname'] . ' ' . $u['lastname'])) ?: ($u['full_name'] ?: 'Unnamed account');
// The account's real role, which may be empty. $role below falls back to
// 'mentee' so the rest of this page has something to branch on, but that
// fallback used to be the only thing shown — an account with no role was
// displayed as a Mentee, which is exactly why nobody noticed these existed.
$raw_role = (string)($u['role'] ?? '');
$has_role = in_array($raw_role, ['mentee', 'mentor', 'admin'], true);
$role  = $u['role'] ?: 'mentee';
$stat  = $u['status'] ?: 'active';
$isMe  = ($uid === $me);
$csrf  = csrf_token();
// Deleted by its owner: kept for the record, with nothing left to act on. It
// used to show as an ordinary blocked account with an Unblock button.
$deleted = ModerationService::isDeleted($u);

/* ── Verification record ──────────────────────────────────────────────── */
$v = VerificationRepository::forUser($con, $uid);

// The profile row is blank for anyone who only ever filled in the
// verification form, so fall back to what they actually submitted.
$pick = function (string $key) use ($u, $v) {
    $a = trim((string)($u[$key] ?? ''));
    if ($a !== '') return $a;
    return trim((string)($v[$key] ?? ''));
};

/* ── Expertise and interests ──────────────────────────────────────────── */
$tags = [];
foreach (AdminUserRepository::tags($con, $uid) as $t) {
    $tags[$t['tag_type']][] = $t['tag'];
}

/* ── Activity ─────────────────────────────────────────────────────────── */
$figures   = AdminUserRepository::activityFigures($con, $uid, $role === 'mentor');
$sess_done = $figures['done'];
$sess_all  = $figures['all'];
$rating    = $figures['rating'];
$rating_n  = $figures['rating_n'];

$report_figures = ModerationRepository::reportFigures($con, $uid);
$rep_open  = $report_figures['open'];
$rep_all   = $report_figures['against'];
$rep_filed = $report_figures['filed'];

$last_seen = !empty($u['email']) ? LogRepository::lastSeen($con, $u['email']) : null;

/* Recent sessions, from whichever side of the table this person sits on. */
$sessions = AdminUserRepository::recentSessions($con, $uid, 6);

/* Ratings written about this person. */
$reviews = AdminUserRepository::reviewsAbout($con, $uid, $role === 'mentor', 4);

/* Reports filed against this person. */
$reports = ModerationRepository::reportsAgainst($con, $uid, 6);

/* Moderation history. Rows are kept after a block or restriction ends. */
$restrictions = ModerationRepository::restrictionsFor($con, $uid, 5);
$blocks       = ModerationRepository::blocksFor($con, $uid, 5);

/* Badges earned. */
$badges = AdminUserRepository::badges($con, $uid);

function uv_date(?string $d, string $fmt = 'M j, Y'): string
{
    return $d ? date($fmt, strtotime($d)) : '—';
}

$current_page = 'users';
include 'layout.php';
?>

<style>
    .uv-wrap { max-width: 1180px; }
    .uv-back {
        display: inline-flex; align-items: center; gap: 7px; margin-bottom: 16px;
        font-size: 13px; font-weight: 600; color: var(--gray-500); text-decoration: none;
    }
    .uv-back:hover { color: var(--forest); }
    .uv-proof { margin-top: 6px; padding: 0; border: 0; background: none; font: inherit; font-size: 12.5px; font-weight: 600; color: var(--forest); text-decoration: underline; cursor: pointer; }
    .uv-back svg { width: 15px; height: 15px; }


    /* ── Identity ── */
    .uv-head {
        display: flex; align-items: flex-start; gap: 18px; flex-wrap: wrap;
        background: #fff; border: 1px solid var(--gray-100); border-radius: 16px;
        padding: 20px 22px; box-shadow: 0 1px 2px rgba(16,24,40,.04); margin-bottom: 14px;
    }
    .uv-av {
        width: 76px; height: 76px; flex: none; border-radius: 50%; overflow: hidden;
        display: grid; place-items: center; background: #E7F0FB; color: #1E4E86;
        font-size: 25px; font-weight: 700;
    }
    .uv-av img { width: 100%; height: 100%; object-fit: cover; }
    .uv-id { flex: 1; min-width: 240px; }
    .uv-id h1 { margin: 0 0 6px; font-size: 24px; font-weight: 700; color: var(--forest); letter-spacing: -.02em; }
    .uv-chips { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; margin-bottom: 9px; }
    .uv-chip { padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; }
    .uv-mentee { background: #E7F3F9; color: #1E6C8C; }
    .uv-mentor { background: #EEEAFB; color: #5340A6; }
    /* Amber, not grey: an account with no role is broken, not merely unset. */
    .uv-norole { background: #FBF0D4; color: #9A7100; }
    .uv-rolefix { display: flex; align-items: center; gap: 7px; }
    .uv-rolefix label { font-size: 12px; font-weight: 700; color: #9A7100; }
    .uv-rolefix select {
        padding: 6px 9px; border: 1px solid #EBD9A6; border-radius: 7px;
        font: inherit; font-size: 12.5px; background: #fff; color: inherit;
    }
    .uv-admin  { background: #FBF0D4; color: #8A6400; }
    .uv-active { background: #E6F5EE; color: #17654B; }
    .uv-restricted { background: #FBF0D4; color: #9A7100; }
    .uv-blocked { background: #FBE5E1; color: #A6301F; }
    .uv-flagged { background: #FBE5E1; color: #A6301F; }
    .uv-muted  { background: var(--gray-100); color: var(--gray-500); }
    .uv-meta { display: flex; gap: 8px 20px; flex-wrap: wrap; font-size: 13px; color: var(--gray-500); }
    .uv-meta span { display: inline-flex; align-items: center; gap: 6px; }
    .uv-meta svg { width: 14px; height: 14px; color: var(--gray-400); }
    .uv-acts { display: flex; gap: 9px; flex-wrap: wrap; align-items: flex-start; }
    .uv-link {
        display: inline-flex; align-items: center; gap: 7px; padding: 9px 15px;
        border: 1px solid var(--gray-200); border-radius: 10px; background: #fff;
        font-family: inherit; font-size: 13px; font-weight: 600; color: var(--gray-700);
        text-decoration: none; cursor: pointer;
    }
    .uv-link:hover { border-color: var(--mint); color: var(--mint-deep, #00539B); }
    .uv-link svg { width: 15px; height: 15px; }
    .uv-link.danger { color: #A6301F; border-color: #F3C9C0; }
    .uv-link.danger:hover { background: #FBE5E1; border-color: #A6301F; }
    .uv-link.warn { color: #9A7100; border-color: #EBD9A6; }
    .uv-link.warn:hover { background: #FBF0D4; border-color: #9A7100; }

    /* ── Standing notice ── */
    .uv-notice {
        display: flex; align-items: flex-start; gap: 11px; padding: 14px 17px;
        border-radius: 13px; font-size: 13.5px; margin-bottom: 14px;
    }
    .uv-notice svg { width: 18px; height: 18px; flex: none; margin-top: 1px; }
    .uv-notice b { display: block; margin-bottom: 2px; }
    .uv-notice.bad { background: #FBE5E1; color: #7C2417; }
    .uv-notice.warn { background: #FBF0D4; color: #7A5A00; }

    /* ── Figures ── */
    .uv-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
    .uv-stat { background: #fff; border: 1px solid var(--gray-100); border-radius: 14px; padding: 15px 17px; box-shadow: 0 1px 2px rgba(16,24,40,.04); }
    .uv-stat-k { font-size: 11.5px; text-transform: uppercase; letter-spacing: .06em; font-weight: 700; color: var(--gray-400); }
    .uv-stat-v { font-size: 26px; font-weight: 700; color: var(--forest); line-height: 1.15; margin-top: 5px; font-variant-numeric: tabular-nums; }
    .uv-stat-s { font-size: 12.5px; color: var(--gray-400); margin-top: 2px; }

    /* ── Body ── */
    .uv-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.15fr); gap: 14px; align-items: start; }
    .uv-card { background: #fff; border: 1px solid var(--gray-100); border-radius: 14px; padding: 17px 19px; box-shadow: 0 1px 2px rgba(16,24,40,.04); margin-bottom: 14px; }
    .uv-card h2 {
        display: flex; align-items: center; justify-content: space-between; gap: 10px;
        margin: 0 0 13px; font-size: 14.5px; font-weight: 700; color: var(--forest);
    }
    .uv-card h2 .uv-chip { font-weight: 700; }
    .uv-kv { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 13px 18px; }
    .uv-k { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--gray-400); font-weight: 700; }
    .uv-v { font-size: 13.5px; color: var(--gray-800); margin-top: 2px; word-break: break-word; }
    .uv-bio { font-size: 13.5px; line-height: 1.6; color: var(--gray-700); margin: 0; white-space: pre-wrap; }
    .uv-tags { display: flex; gap: 7px; flex-wrap: wrap; }
    .uv-tag { padding: 4px 11px; border-radius: 999px; background: var(--gray-50, #F7F8FA); border: 1px solid var(--gray-100); font-size: 12.5px; color: var(--gray-700); }
    .uv-tagset + .uv-tagset { margin-top: 12px; }

    .uv-rows { display: flex; flex-direction: column; }
    .uv-row { display: flex; align-items: flex-start; gap: 12px; padding: 11px 0; border-top: 1px solid var(--gray-100); font-size: 13.5px; }
    .uv-row:first-child { border-top: 0; padding-top: 0; }
    .uv-row-main { flex: 1; min-width: 0; }
    .uv-row-t { font-weight: 600; color: var(--gray-800); }
    .uv-row-s { font-size: 12.5px; color: var(--gray-400); margin-top: 2px; }
    .uv-row-note { font-size: 13px; color: var(--gray-600); margin-top: 5px; line-height: 1.55; }
    .uv-row-when { font-size: 12.5px; color: var(--gray-400); white-space: nowrap; }
    .uv-stars { color: #E5A800; font-size: 13px; letter-spacing: 1px; }

    .uv-none { font-size: 13px; color: var(--gray-400); margin: 0; }

    @media (max-width: 1080px) {
        .uv-grid { grid-template-columns: minmax(0, 1fr); }
        .uv-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 560px) {
        .uv-acts { width: 100%; }
    }
</style>

<div class="uv-wrap">

    <a class="uv-back" href="<?= url('admin-users') ?>">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
        Back to User Management
    </a>

    <!-- ══════════ Identity ══════════ -->
    <div class="uv-head">
        <span class="uv-av">
            <?php if (!empty($u['profile_image'])): ?>
                <img src="<?= htmlspecialchars($u['profile_image']) ?>" alt="">
            <?php else: ?>
                <?= htmlspecialchars(strtoupper(substr($u['firstname'], 0, 1) . substr($u['lastname'], 0, 1))) ?>
            <?php endif; ?>
        </span>

        <div class="uv-id">
            <h1><?= htmlspecialchars($name) ?></h1>
            <div class="uv-chips">
                <?php if ($has_role): ?>
                    <span class="uv-chip uv-<?= htmlspecialchars($raw_role) ?>"><?= htmlspecialchars(ucfirst($raw_role)) ?></span>
                <?php else: ?>
                    <span class="uv-chip uv-norole" title="This account has no role, so every dashboard turns it away.">No role</span>
                <?php endif; ?>
                <span class="uv-chip uv-<?= htmlspecialchars($stat) ?>"><?= $deleted ? 'Deleted' : htmlspecialchars(ucfirst($stat)) ?></span>
                <?php if (!(int)$u['verified']): ?><span class="uv-chip uv-muted">Unverified</span><?php endif; ?>
                <?php if ($rep_open > 0): ?><span class="uv-chip uv-flagged"><?= $rep_open ?> open report<?= $rep_open === 1 ? '' : 's' ?></span><?php endif; ?>
                <?php if ($isMe): ?><span class="uv-chip uv-muted">You</span><?php endif; ?>
            </div>
            <div class="uv-meta">
                <span>
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="5.5" width="18" height="13" rx="2.5" /><path stroke-linecap="round" d="m4 7 8 6 8-6" /></svg>
                    <?= htmlspecialchars($u['email'] ?: 'No email on file') ?>
                </span>
                <?php if (!empty($u['username'])): ?>
                    <span>
                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5" /><path stroke-linecap="round" d="M5 20a7 7 0 0 1 14 0" /></svg>
                        @<?= htmlspecialchars($u['username']) ?>
                    </span>
                <?php endif; ?>
                <span>
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="15" rx="2.5" /><path stroke-linecap="round" d="M8 3v4M16 3v4M3.5 10h17" /></svg>
                    Joined <?= uv_date($u['created_at']) ?>
                </span>
                <span>
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7.5V12l3 2" /></svg>
                    <?= $last_seen ? 'Last active ' . uv_date($last_seen, 'M j, Y g:i A') : 'Never signed in' ?>
                </span>
            </div>
        </div>

        <div class="uv-acts">
            <?php if ($raw_role === 'mentor' && !$deleted): ?>
                <a class="uv-link" target="_blank" rel="noopener" href="<?= htmlspecialchars(url('mentee-view-mentor') . '?id=' . $uid) ?>">
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5h5v5M19 5l-8 8M18 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" /></svg>
                    Public profile
                </a>
            <?php endif; ?>

            <?php if (!$deleted): ?>
                <a class="uv-link" href="<?= htmlspecialchars(url('messages') . '?chat=' . $uid) ?>">
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12a7 7 0 0 1-7 7H8.5L5 21.5V18A7 7 0 0 1 12 5h1a7 7 0 0 1 7 7Z" /></svg>
                    Message
                </a>
            <?php endif; ?>

            <?php if (!$has_role && !$isMe && !$deleted): ?>
                <form method="post" action="<?= url('admin-action-role') ?>" class="uv-rolefix">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                    <label for="uv-role-pick">Set role</label>
                    <select id="uv-role-pick" name="role" required>
                        <option value="">Choose…</option>
                        <option value="mentee">Mentee</option>
                        <option value="mentor">Mentor</option>
                    </select>
                    <button type="submit" class="uv-link">Save</button>
                </form>
            <?php endif; ?>

            <?php if (!$isMe && !$deleted): ?>
                <?php if ($stat === 'blocked'): ?>
                    <form method="post" action="<?= url('admin-action-unblock') ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                        <button type="submit" class="uv-link">
                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="4.5" y="11" width="15" height="9" rx="2" /><path stroke-linecap="round" d="M8 11V7.5a4 4 0 0 1 7.7-1.5" /></svg>
                            Unblock
                        </button>
                    </form>
                <?php else: ?>
                    <?php // A restriction does not apply to the admin panel, so an admin is never offered one. ?>
                    <?php if ($raw_role !== 'admin'): ?>
                        <button type="button" class="uv-link warn"
                            onclick="umRestrict(<?= $uid ?>, 0, <?= htmlspecialchars(json_encode($name), ENT_QUOTES) ?>)">
                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7.5V12l3 2" /></svg>
                            <?= $stat === 'restricted' ? 'Change restriction' : 'Restrict' ?>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="uv-link danger"
                        onclick="umBlock(<?= $uid ?>, 0, <?= htmlspecialchars(json_encode($name), ENT_QUOTES) ?>)">
                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="m6.5 6.5 11 11" /></svg>
                        Block
                    </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══════════ Standing notice ══════════ -->
    <?php if ($deleted):
        $b = $blocks[0] ?? null; ?>
        <div class="uv-notice bad">
            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="m6.5 6.5 11 11" /></svg>
            <div>
                <b>This account was deleted by its owner<?= $b ? ' — ' . htmlspecialchars(uv_date($b['blocked_at'])) : '' ?></b>
                Its name, email address and profile were removed. Its sessions and reviews stay, because they belong to the other people in them too.
            </div>
        </div>
    <?php elseif ($stat === 'blocked'):
        $b = $blocks[0] ?? null; ?>
        <div class="uv-notice bad">
            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="m6.5 6.5 11 11" /></svg>
            <div>
                <b>This account is blocked<?= $b ? ' — ' . htmlspecialchars(uv_date($b['blocked_at'])) : '' ?></b>
                <?= $b && trim((string)$b['reason']) !== '' ? htmlspecialchars($b['reason']) : 'No reason was recorded.' ?>
            </div>
        </div>
    <?php elseif ($stat === 'restricted'):
        $x = $restrictions[0] ?? null; ?>
        <div class="uv-notice warn">
            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7.5V12l3 2" /></svg>
            <div>
                <b>Restricted<?= $x && $x['end_date'] ? ' until ' . htmlspecialchars(uv_date(ModerationService::liftDay($x['end_date']))) : '' ?></b>
                They can sign in and read, but cannot book, message or publish.
                <?= $x && trim((string)$x['reason']) !== '' ? ' Reason: ' . htmlspecialchars($x['reason']) : '' ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ══════════ Figures ══════════ -->
    <div class="uv-stats">
        <div class="uv-stat">
            <div class="uv-stat-k">Sessions</div>
            <div class="uv-stat-v"><?= $sess_done ?></div>
            <div class="uv-stat-s"><?= $sess_all === 0 ? 'None booked yet' : ($sess_all === $sess_done ? 'All completed' : $sess_all . ' booked in total') ?></div>
        </div>
        <div class="uv-stat">
            <div class="uv-stat-k">Rating</div>
            <div class="uv-stat-v"><?= $rating !== null ? number_format((float)$rating, 1) : '—' ?></div>
            <div class="uv-stat-s"><?= $rating_n > 0 ? 'From ' . $rating_n . ' review' . ($rating_n === 1 ? '' : 's') : 'No ratings yet' ?></div>
        </div>
        <div class="uv-stat">
            <div class="uv-stat-k">Reports against</div>
            <div class="uv-stat-v"><?= $rep_all ?></div>
            <div class="uv-stat-s"><?= $rep_open > 0 ? $rep_open . ' still open' : 'None open' ?></div>
        </div>
        <div class="uv-stat">
            <div class="uv-stat-k">Reports filed</div>
            <div class="uv-stat-v"><?= $rep_filed ?></div>
            <div class="uv-stat-s">By this member</div>
        </div>
    </div>

    <div class="uv-grid">
        <!-- ── Left column ── -->
        <div>
            <div class="uv-card">
                <h2>Student details</h2>
                <div class="uv-kv">
                    <?php foreach ([
                        'Student ID' => $pick('student_id'),
                        'Course'     => $pick('course'),
                        'Year level' => $pick('year_level'),
                        'Section'    => trim((string)($u['section'] ?? '')),
                        'Club'       => $pick('club'),
                        'Location'   => trim((string)($u['location'] ?? '')),
                        'Phone'      => trim((string)($u['phone'] ?? '')),
                    ] as $k => $val): ?>
                        <div>
                            <div class="uv-k"><?= $k ?></div>
                            <div class="uv-v"><?= $val !== '' ? htmlspecialchars($val) : '—' ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="uv-card">
                <h2>
                    About
                    <?php if (($u['visibility'] ?? '') !== '' && $u['visibility'] !== 'everyone'): ?>
                        <span class="uv-chip uv-muted">Visible to <?= htmlspecialchars($u['visibility']) ?></span>
                    <?php endif; ?>
                </h2>
                <?php if (trim((string)($u['bio'] ?? '')) !== ''): ?>
                    <p class="uv-bio"><?= htmlspecialchars($u['bio']) ?></p>
                <?php else: ?>
                    <p class="uv-none">They have not written a bio.</p>
                <?php endif; ?>
            </div>

            <div class="uv-card">
                <h2>Expertise and interests</h2>
                <?php if (!$tags): ?>
                    <p class="uv-none">No subjects or skills recorded.</p>
                <?php else: foreach ([
                        'skill'    => 'Skills',
                        'interest' => 'Interests',
                        'learn'    => 'Wants to learn',
                    ] as $type => $label): ?>
                        <?php if (!empty($tags[$type])): ?>
                            <div class="uv-tagset">
                                <div class="uv-k" style="margin-bottom:6px;"><?= $label ?></div>
                                <div class="uv-tags">
                                    <?php foreach ($tags[$type] as $tag): ?>
                                        <span class="uv-tag"><?= htmlspecialchars($tag) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach;
                    // Anything stored under a type this page does not name yet.
                    foreach ($tags as $type => $list):
                        if (in_array($type, ['skill', 'interest', 'learn'], true)) continue; ?>
                        <div class="uv-tagset">
                            <div class="uv-k" style="margin-bottom:6px;"><?= htmlspecialchars(ucfirst($type)) ?></div>
                            <div class="uv-tags">
                                <?php foreach ($list as $tag): ?><span class="uv-tag"><?= htmlspecialchars($tag) ?></span><?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
            </div>

            <?php if ($badges): ?>
                <div class="uv-card">
                    <h2>Badges</h2>
                    <div class="uv-rows">
                        <?php foreach ($badges as $b): ?>
                            <div class="uv-row">
                                <div class="uv-row-main">
                                    <div class="uv-row-t"><?= htmlspecialchars($b['name']) ?></div>
                                    <?php if (trim((string)$b['description']) !== ''): ?>
                                        <div class="uv-row-s"><?= htmlspecialchars($b['description']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="uv-row-when"><?= uv_date($b['awarded_at']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── Right column ── -->
        <div>
            <div class="uv-card">
                <h2>
                    Verification
                    <?php if ($v): ?>
                        <span class="uv-chip uv-<?= $v['status'] === 'approved' ? 'active' : ($v['status'] === 'rejected' ? 'blocked' : 'restricted') ?>">
                            <?= htmlspecialchars(ucfirst($v['status'])) ?>
                        </span>
                    <?php else: ?>
                        <span class="uv-chip uv-muted">Never submitted</span>
                    <?php endif; ?>
                </h2>

                <?php if (!$v): ?>
                    <p class="uv-none">This member has not submitted the verification form.</p>
                <?php else: ?>
                    <div class="uv-kv" style="margin-bottom:14px;">
                        <div><div class="uv-k">Submitted</div><div class="uv-v"><?= uv_date($v['submitted_at']) ?></div></div>
                        <div><div class="uv-k">Reviewed</div><div class="uv-v"><?= uv_date($v['reviewed_at']) ?></div></div>
                        <div><div class="uv-k">Name given</div><div class="uv-v"><?= htmlspecialchars($v['full_name'] ?: '—') ?></div></div>
                        <?php if (trim((string)($v['expertise'] ?? '')) !== ''): ?>
                            <div><div class="uv-k">Expertise</div><div class="uv-v"><?= htmlspecialchars($v['expertise']) ?></div></div>
                        <?php endif; ?>
                    </div>

                    <?php if (trim((string)($v['admin_notes'] ?? '')) !== ''): ?>
                        <div style="margin-bottom:14px;">
                            <div class="uv-k">Reviewer's note</div>
                            <div class="uv-row-note"><?= nl2br(htmlspecialchars($v['admin_notes'])) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($v['id_image']) || !empty($v['credential_image'])): ?>
                        <div class="uv-k" style="margin-bottom:7px;">Documents submitted</div>
                        <div class="um-vdocs">
                            <?php um_doc_tile($v['id_image'] ?? '', 'School ID', $name); ?>
                            <?php um_doc_tile($v['credential_image'] ?? '', 'Credential', $name); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($v['status'] === 'pending'): ?>
                        <div style="display:flex;gap:9px;flex-wrap:wrap;">
                            <form method="post" action="<?= url('admin-action-verify') ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="verification_id" value="<?= (int)$v['verification_id'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="um-btn um-ok">Approve</button>
                            </form>
                            <button type="button" class="um-btn um-no"
                                onclick="umReject(<?= (int)$v['verification_id'] ?>, <?= htmlspecialchars(json_encode($name), ENT_QUOTES) ?>)">Reject</button>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="uv-card">
                <h2>Recent sessions</h2>
                <?php if (!$sessions): ?>
                    <p class="uv-none">No sessions booked yet.</p>
                <?php else: ?>
                    <div class="uv-rows">
                        <?php foreach ($sessions as $s):
                            $asMentor = (int)$s['mentor_id'] === $uid; ?>
                            <div class="uv-row">
                                <div class="uv-row-main">
                                    <div class="uv-row-t"><?= htmlspecialchars($s['subject'] ?: 'Untitled session') ?></div>
                                    <div class="uv-row-s">
                                        <?= $asMentor ? 'Mentored' : 'With' ?>
                                        <?= htmlspecialchars(trim($s['other_name']) ?: 'a removed account') ?>
                                        &middot; <?= htmlspecialchars(ucfirst($s['status'])) ?>
                                    </div>
                                </div>
                                <div class="uv-row-when"><?= uv_date($s['session_date']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="uv-card">
                <h2>Ratings received</h2>
                <?php if (!$reviews): ?>
                    <p class="uv-none">Nobody has rated this member yet.</p>
                <?php else: ?>
                    <div class="uv-rows">
                        <?php foreach ($reviews as $f):
                            $stars = (int)round((float)$f['rating']); ?>
                            <div class="uv-row">
                                <div class="uv-row-main">
                                    <div class="uv-stars"><?= str_repeat('★', max(0, min(5, $stars))) . str_repeat('☆', max(0, 5 - $stars)) ?></div>
                                    <div class="uv-row-s">From <?= htmlspecialchars(trim((string)$f['author']) ?: 'a removed account') ?></div>
                                    <?php if (trim((string)$f['comment']) !== ''): ?>
                                        <div class="uv-row-note"><?= nl2br(htmlspecialchars($f['comment'])) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="uv-row-when"><?= uv_date($f['created_at']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="uv-card">
                <h2>
                    Reports
                    <?php if ($rep_open > 0): ?><span class="uv-chip uv-flagged"><?= $rep_open ?> open</span><?php endif; ?>
                </h2>
                <?php if (!$reports): ?>
                    <p class="uv-none">Nobody has reported this member.</p>
                <?php else: ?>
                    <div class="uv-rows">
                        <?php foreach ($reports as $r): ?>
                            <div class="uv-row">
                                <div class="uv-row-main">
                                    <div class="uv-row-t"><?= htmlspecialchars(ReportService::label($r['issue_type'])) ?></div>
                                    <div class="uv-row-s">
                                        By <?= htmlspecialchars(trim((string)$r['reporter']) ?: 'a removed account') ?>
                                        &middot; <?= htmlspecialchars(ucfirst($r['status'])) ?>
                                    </div>
                                    <?php if (trim((string)$r['description']) !== ''): ?>
                                        <div class="uv-row-note"><?= nl2br(htmlspecialchars($r['description'])) ?></div>
                                    <?php endif; ?>
                                    <?php if (($proof = ReportService::proofUrl($r['proof'] ?? null)) !== ''): ?>
                                        <button type="button" class="uv-proof"
                                            onclick="umDoc(<?= htmlspecialchars(json_encode($proof), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode('Attached to a report about ' . $name), ENT_QUOTES) ?>)">View attached image</button>
                                    <?php endif; ?>
                                </div>
                                <div class="uv-row-when"><?= uv_date($r['created_at']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($restrictions || $blocks): ?>
                <div class="uv-card">
                    <h2>Moderation history</h2>
                    <div class="uv-rows">
                        <?php foreach ($blocks as $i => $b):
                            // Only the newest block of a blocked account is in force;
                            // every other one was lifted by an unblock.
                            $in_force = $i === 0 && $stat === 'blocked'; ?>
                            <div class="uv-row">
                                <div class="uv-row-main">
                                    <div class="uv-row-t"><?= $in_force ? ($deleted ? 'Account deleted' : 'Blocked') : 'Blocked, since lifted' ?></div>
                                    <div class="uv-row-note"><?= trim((string)$b['reason']) !== '' ? htmlspecialchars($b['reason']) : 'No reason recorded.' ?></div>
                                </div>
                                <div class="uv-row-when"><?= uv_date($b['blocked_at']) ?></div>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach ($restrictions as $i => $x):
                            // A newer restriction that began before this one ran out replaced it.
                            $newer    = $restrictions[$i - 1] ?? null;
                            $replaced = $newer && $x['end_date'] && (string)$newer['start_date'] <= (string)$x['end_date']; ?>
                            <div class="uv-row">
                                <div class="uv-row-main">
                                    <div class="uv-row-t">Restricted<?= $x['end_date'] ? ' until ' . htmlspecialchars(uv_date(ModerationService::liftDay($x['end_date']))) : '' ?><?= $replaced ? ' — replaced ' . htmlspecialchars(uv_date($newer['start_date'])) : '' ?></div>
                                    <div class="uv-row-s">By <?= htmlspecialchars(trim((string)$x['by_name']) ?: 'an admin') ?></div>
                                    <div class="uv-row-note"><?= trim((string)$x['reason']) !== '' ? htmlspecialchars($x['reason']) : 'No reason recorded.' ?></div>
                                </div>
                                <div class="uv-row-when"><?= uv_date($x['restricted_at']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/moderation_ui.php'; ?>
