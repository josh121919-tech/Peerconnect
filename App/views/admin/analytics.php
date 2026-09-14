<?php

/**
 * admin/analytics.php — Reports & Analytics → Platform analytics.
 *
 * Where the Overall summary answers "how did the platform do over this date
 * range", this page answers "who is doing the mentoring, and how well". It is
 * all-time and mentor-centred: the score each mentor carries, how it was
 * arrived at, who is missing sessions, and which badges that has earned.
 *
 * On the score: it is a weighted formula, not a model. Every input is a count
 * or an average of rows in this database, and the weights are fixed in
 * App/services/MentorScoreService.php. The page prints those weights beside the
 * table, because a number between 0 and 100 that nobody can explain is worse
 * than no number at all — this one was previously labelled "AI Score", which
 * claimed considerably more than the arithmetic does.
 *
 * Both buttons in the header do real work and both have side effects: one
 * writes 'missed' onto sessions nobody attended, the other awards badges and
 * emails the mentors who earn them. They confirm before running.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$active_page = 'admin-analytics';
// The nav keys a group's children by the page name each one sets, minus the
// 'admin-' prefix, so this is 'analytics' — see the Reports group in layout.php.
$current_page = 'analytics';

// ── Helper: safe int query ─────────────────────────────────────────────────
function db_stat(mysqli $con, string $sql, array $params = []): int
{
    if (empty($params)) {
        $r = $con->query($sql);
        return $r ? (int)$r->fetch_row()[0] : 0;
    }
    [$types, $vals] = $params;
    $st = $con->prepare($sql);
    $st->bind_param($types, ...$vals);
    $st->execute();
    return (int)$st->get_result()->fetch_row()[0];
}

// ── Core platform stats ───────────────────────────────────────────────────
$total_mentors    = db_stat($con, "SELECT COUNT(*) FROM users WHERE role='mentor'");
$total_mentees    = db_stat($con, "SELECT COUNT(*) FROM users WHERE role='mentee'");
$active_mentors   = db_stat($con, "SELECT COUNT(*) FROM users WHERE role='mentor' AND status='active' AND verified=1");
$active_mentees   = db_stat($con, "SELECT COUNT(*) FROM users WHERE role='mentee' AND status='active'");
$verified_mentors = db_stat($con, "SELECT COUNT(*) FROM users WHERE role='mentor' AND verified=1");

// ── Session stats ─────────────────────────────────────────────────────────
$today        = date('Y-m-d');
$week_start   = date('Y-m-d', strtotime('monday this week'));

$sessions_today    = db_stat($con, "SELECT COUNT(*) FROM session_requests WHERE DATE(session_date)=? AND status IN ('approved','completed')", ['s', [$today]]);
$sessions_week     = db_stat($con, "SELECT COUNT(*) FROM session_requests WHERE session_date >= ? AND status IN ('approved','completed')", ['s', [$week_start]]);
$sessions_total    = db_stat($con, "SELECT COUNT(*) FROM session_requests WHERE status='completed'");
$sessions_pending  = db_stat($con, "SELECT COUNT(*) FROM session_requests WHERE status='pending'");
$sessions_missed   = db_stat($con, "SELECT COUNT(*) FROM session_requests WHERE status='missed'");
$sessions_cancelled = db_stat($con, "SELECT COUNT(*) FROM session_requests WHERE status='cancelled'");
$sessions_rejected = db_stat($con, "SELECT COUNT(*) FROM session_requests WHERE status='rejected'");

// ── Completion rate ───────────────────────────────────────────────────────
$sessions_closed   = db_stat($con, "SELECT COUNT(*) FROM session_requests WHERE status IN ('completed','missed','cancelled')");
$completion_rate   = $sessions_closed > 0 ? round($sessions_total / $sessions_closed * 100) : 0;

// ── Avg platform rating ───────────────────────────────────────────────────
$avg_rating_row = $con->query("SELECT ROUND(AVG(rating),2) as r, COUNT(*) as c FROM feedback WHERE rating > 0")->fetch_assoc();
$platform_rating = (float)($avg_rating_row['r'] ?? 0);
$total_reviews   = (int)($avg_rating_row['c'] ?? 0);

// ── Monthly registrations — 12 months ─────────────────────────────────────
// Each statement is executed, fetched and freed before the next runs: two
// prepared statements sharing one connection otherwise give "commands out of
// sync" the moment a result is left hanging.
$reg_data  = [];
$sess_data = [];
$reg_stmt  = $con->prepare("SELECT COUNT(*) FROM users WHERE role <> 'admin' AND DATE_FORMAT(created_at,'%Y-%m')=?");
$sess_stmt = $con->prepare("SELECT COUNT(*) FROM session_requests WHERE status='completed' AND DATE_FORMAT(session_date,'%Y-%m')=?");
for ($i = 11; $i >= 0; $i--) {
    $ym    = date('Y-m', strtotime("-{$i} months"));
    $label = date('M', strtotime("-{$i} months"));

    $reg_stmt->bind_param("s", $ym);
    $reg_stmt->execute();
    $reg_res = $reg_stmt->get_result();
    $reg_count = (int)($reg_res->fetch_row()[0] ?? 0);
    $reg_res->free();
    $reg_data[] = ['label' => $label, 'full' => date('M Y', strtotime("-{$i} months")), 'count' => $reg_count];

    $sess_stmt->bind_param("s", $ym);
    $sess_stmt->execute();
    $sess_res = $sess_stmt->get_result();
    $sess_count = (int)($sess_res->fetch_row()[0] ?? 0);
    $sess_res->free();
    $sess_data[] = ['label' => $label, 'full' => date('M Y', strtotime("-{$i} months")), 'count' => $sess_count];
}
$reg_stmt->close();
$sess_stmt->close();

// ── Popular subjects (top 8) ───────────────────────────────────────────────
$pop_subjects = $con->query("
    SELECT subject, COUNT(*) as cnt
    FROM session_requests
    WHERE subject IS NOT NULL AND subject != ''
    GROUP BY subject
    ORDER BY cnt DESC
    LIMIT 8
")->fetch_all(MYSQLI_ASSOC);

// ── Mentor scores ─────────────────────────────────────────────────────────
$top_mentors = $con->query("
    SELECT
        u.user_id, u.firstname, u.lastname,
        pr.profile_image,
        ms.last_calculated,
        COALESCE(ms.avg_rating, 0)           AS avg_rating,
        COALESCE(ms.total_sessions, 0)       AS total_sessions,
        COALESCE(ms.completion_rate, 0)      AS completion_rate,
        COALESCE(ms.effectiveness_score, 0)  AS effectiveness_score,
        COALESCE(ms.recommendation_score, 0) AS rec_score,
        (SELECT COUNT(*) FROM feedback f WHERE f.mentor_id = u.user_id) AS review_count
    FROM users u
    LEFT JOIN mentor_scores ms ON ms.mentor_id = u.user_id
    LEFT JOIN profile pr       ON pr.user_id   = u.user_id
    WHERE u.role = 'mentor' AND u.status = 'active'
    ORDER BY rec_score DESC, avg_rating DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// When the scores were last worked out, and who has none. A score that was
// computed before this week's sessions is stale, and silently showing it as
// current is the kind of thing that gets acted on.
$scores_at = $con->query("SELECT MAX(last_calculated) FROM mentor_scores")->fetch_row()[0] ?? null;
$unscored  = db_stat($con, "
    SELECT COUNT(*) FROM users u
     WHERE u.role='mentor' AND u.status='active' AND u.verified=1
       AND NOT EXISTS (SELECT 1 FROM mentor_scores ms WHERE ms.mentor_id = u.user_id)");

// The weights the score is actually built from, read off MentorScoreService.
$score_weights = [
    ['Rating from mentees',   40, 'Average stars, blended toward 3.5 until a mentor has enough reviews to stand on their own', '#1B6FD1'],
    ['Session completion',    25, 'Completed ÷ every session that closed — pending and upcoming ones are not counted either way', '#17654B'],
    ['Experience',            15, 'Completed sessions on a log scale, so the tenth session counts for less than the first', '#5A3E96'],
    ['Detailed feedback',     10, 'Communication, knowledge, skill and efficiency, averaged from the review form', '#B7791F'],
    ['Attendance',            10, 'Share of sessions the mentor turned up to', '#0087CF'],
];

// ── Missed session breakdown ───────────────────────────────────────────────
$missed_mentors = $con->query("
    SELECT u.user_id, u.firstname, u.lastname, COUNT(*) as cnt
    FROM session_requests sr
    JOIN users u ON u.user_id = sr.mentor_id
    WHERE sr.status = 'missed'
    GROUP BY sr.mentor_id
    ORDER BY cnt DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Who was actually absent, which is not the same question as which sessions
// were marked missed — either side, or both, can fail to show up.
$missed_by = [];
$mb = $con->query("SELECT missed_by, COUNT(*) n FROM session_requests WHERE status='missed' GROUP BY missed_by");
while ($row = $mb->fetch_assoc()) $missed_by[$row['missed_by'] ?: 'none'] = (int)$row['n'];

// ── Account standing ──────────────────────────────────────────────────────
// users.status is an enum of exactly these three. An earlier version of this
// card also drew a "Pending" row, which the column cannot hold, so it reported
// zero for ever regardless of how many people were waiting.
$status_data = [
    'Active'     => db_stat($con, "SELECT COUNT(*) FROM users WHERE status='active' AND role <> 'admin'"),
    'Restricted' => db_stat($con, "SELECT COUNT(*) FROM users WHERE status='restricted' AND role <> 'admin'"),
    'Blocked'    => db_stat($con, "SELECT COUNT(*) FROM users WHERE status='blocked' AND role <> 'admin'"),
];
$verif_data = [
    'Verified'     => db_stat($con, "SELECT COUNT(*) FROM users WHERE verified=1 AND role <> 'admin'"),
    'Not verified' => db_stat($con, "SELECT COUNT(*) FROM users WHERE (verified=0 OR verified IS NULL) AND role <> 'admin'"),
];
$verif_waiting = db_stat($con, "SELECT COUNT(*) FROM user_verifications WHERE status='pending'");

// ── Badge stats ────────────────────────────────────────────────────────────
$total_badges_awarded = db_stat($con, "SELECT COUNT(*) FROM user_badges");
$auto_badges_awarded  = db_stat($con, "SELECT COUNT(*) FROM user_badges WHERE awarded_by IS NULL");

$badge_rows = $con->query("
    SELECT b.badge_id, b.name, b.criteria_type, b.criteria_value, b.is_active,
           COUNT(ub.user_badge_id) AS cnt
    FROM badges b
    LEFT JOIN user_badges ub ON ub.badge_id = b.badge_id
    GROUP BY b.badge_id
    ORDER BY cnt DESC, b.badge_id
")->fetch_all(MYSQLI_ASSOC);

/** What a badge's rule actually says, in words. */
function an_criteria(array $b): string
{
    $v = (int)$b['criteria_value'];
    switch ($b['criteria_type']) {
        case 'sessions_completed': return $v . ' completed session' . ($v === 1 ? '' : 's');
        case 'avg_rating':         return number_format($v / 10, 1) . '★ average, 5+ sessions';
        case 'top_mentor':         return 'Top mentor';
        case 'community':          return 'Awarded by hand';
        default:                   return 'Awarded by hand';
    }
}

$csrf = csrf_token();

include 'layout.php';
require_once __DIR__ . '/includes/sessions_ui.php';
require_once __DIR__ . '/includes/report_data.php';
?>

<style>
    .an-crumb { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--gray-400); margin-bottom: 6px; }
    .an-crumb a { color: var(--gray-500); text-decoration: none; font-weight: 600; }
    .an-crumb a:hover { color: var(--mint); }
    .an-crumb b { color: var(--forest); font-weight: 600; }

    .an-run {
        display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px;
        border: 1px solid var(--gray-200); border-radius: 11px; background: #fff;
        font-family: inherit; font-size: 13.5px; font-weight: 600; color: var(--gray-700); cursor: pointer;
    }
    .an-run:hover { border-color: var(--mint); color: var(--mint); }
    .an-run svg { width: 15px; height: 15px; }
    .an-run.primary { background: var(--forest); border-color: var(--forest); color: #fff; }
    .an-run.primary:hover { background: #0B1440; color: #fff; }

    .an-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 12px; }
    .an-stats:last-of-type { margin-bottom: 16px; }

    /*
     * Cards in a row stretch to the row's height rather than each ending
     * wherever its content happens to stop. Left to themselves they finish at
     * different points and the page reads as a set of ragged holes; the fix is
     * partly this and mostly pairing cards that hold comparable amounts.
     */
    .an-cols   { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-bottom: 14px; }
    .an-cols-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 14px; }
    .an-cols > .ss-card, .an-cols-3 > .ss-card { display: flex; flex-direction: column; }
    .an-full { margin-bottom: 14px; }

    .an-sub { margin: -9px 0 14px; font-size: 12px; color: var(--gray-400); line-height: 1.55; }
    .an-sub a { color: var(--mint); font-weight: 600; text-decoration: none; }

    /* ── Leaderboard ── */
    .an-lb { width: 100%; border-collapse: collapse; font-size: 13px; }
    .an-lb th {
        text-align: left; padding: 0 8px 9px; font-size: 10.5px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .05em; color: var(--gray-400); white-space: nowrap;
    }
    .an-lb th:first-child, .an-lb td:first-child { padding-left: 0; }
    .an-lb th:last-child, .an-lb td:last-child { padding-right: 0; text-align: right; }
    .an-lb td { padding: 11px 8px; border-top: 1px solid var(--gray-100); vertical-align: middle; }
    .an-lb tbody tr:hover { background: #FAFBFC; }
    .an-lb .num { text-align: center; font-variant-numeric: tabular-nums; }

    .an-rank {
        width: 24px; height: 24px; border-radius: 8px; display: grid; place-items: center;
        font-size: 11.5px; font-weight: 700; background: var(--gray-100); color: var(--gray-500);
    }
    .an-rank.top { background: #FEF6DC; color: #8A6400; }

    .an-who { display: flex; align-items: center; gap: 10px; min-width: 0; }
    .an-av {
        width: 32px; height: 32px; flex: none; border-radius: 50%; overflow: hidden;
        background: var(--forest); color: #fff; display: grid; place-items: center;
        font-size: 12px; font-weight: 700;
    }
    .an-av img { width: 100%; height: 100%; object-fit: cover; }
    .an-who b { font-size: 13px; font-weight: 600; color: var(--gray-800); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .an-who a { color: inherit; text-decoration: none; }
    .an-who a:hover b { color: var(--mint); }
    /* Named, not `.an-who span` — a type-qualified selector like that also
       matches the avatar beside it and wins on specificity, which repainted
       the initial in grey on the same dark circle. */
    .an-who-s { display: block; font-size: 11px; color: var(--gray-400); }

    .an-pill {
        display: inline-block; min-width: 46px; padding: 3px 9px; border-radius: 999px;
        font-size: 12px; font-weight: 700; font-variant-numeric: tabular-nums;
    }
    .an-pill.high { background: #E6F5EE; color: #17654B; }
    .an-pill.mid  { background: #FEF6DC; color: #8A6400; }
    .an-pill.low  { background: #FBE5E1; color: #A6301F; }

    /* ── Weight rows ── */
    /* Five parts of one hundred, so they are laid out as five parts of one
       row rather than as a list that happens to add up. */
    .an-w-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(168px, 1fr)); gap: 16px; }
    .an-w { padding-top: 11px; border-top: 3px solid var(--gray-200); }
    .an-w-n { display: block; font-size: 21px; font-weight: 700; color: var(--forest);
              letter-spacing: -.02em; line-height: 1; font-variant-numeric: tabular-nums; }
    .an-w-t { display: block; margin-top: 7px; }
    .an-w-t b { display: block; font-size: 12.5px; font-weight: 600; color: var(--gray-800); }
    .an-w-t span { display: block; font-size: 11.5px; color: var(--gray-400); line-height: 1.5; margin-top: 3px; }

    /* ── Charts ── */
    .an-chart { width: 100%; height: 160px; display: block; overflow: visible; }
    .an-axis { font-size: 9.5px; fill: var(--gray-400); font-family: inherit; }
    .an-legend { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 10px; font-size: 11.5px; color: var(--gray-500); font-weight: 600; }
    .an-legend span { display: inline-flex; align-items: center; gap: 6px; }
    .an-legend i { width: 9px; height: 9px; border-radius: 3px; flex: none; }

    /* ── Bars ── */
    .an-bar { display: flex; align-items: center; gap: 10px; font-size: 12.5px; color: var(--gray-700); padding: 6px 0; }
    .an-bar > b { width: 120px; flex: none; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .an-bar-t { flex: 1; height: 7px; border-radius: 999px; background: var(--gray-100); overflow: hidden; }
    .an-bar-t i { display: block; height: 100%; border-radius: 999px; }
    .an-bar-n { flex: none; width: 30px; text-align: right; font-variant-numeric: tabular-nums; color: var(--gray-500); font-weight: 600; }
    .an-bar a { color: inherit; text-decoration: none; }
    .an-bar a:hover { color: var(--mint); }

    .an-group { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
                color: var(--gray-400); margin: 16px 0 6px; }
    .an-group:first-of-type { margin-top: 0; }

    /* ── Badge rows ── */
    .an-badge { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-top: 1px solid var(--gray-100); }
    .an-badge:first-of-type { border-top: 0; padding-top: 0; }
    .an-badge-i { width: 30px; height: 30px; flex: none; border-radius: 9px; display: grid; place-items: center;
                  background: #FEF6DC; color: #8A6400; }
    .an-badge-i svg { width: 16px; height: 16px; }
    .an-badge-t { flex: 1; min-width: 0; }
    .an-badge-t b { display: block; font-size: 12.5px; font-weight: 600; color: var(--gray-800); }
    .an-badge-t span { display: block; font-size: 11.5px; color: var(--gray-400); }
    .an-badge-n { flex: none; font-size: 13px; font-weight: 700; color: var(--forest); font-variant-numeric: tabular-nums; }
    .an-badge.zero .an-badge-i { background: var(--gray-100); color: var(--gray-400); }
    .an-badge.zero .an-badge-n { color: var(--gray-300); }

    .an-jump { display: flex; flex-wrap: wrap; gap: 9px; }
    .an-jump a {
        display: inline-flex; align-items: center; gap: 8px; padding: 9px 15px;
        border: 1px solid var(--gray-200); border-radius: 10px; background: #fff;
        font-size: 13px; font-weight: 600; color: var(--gray-700); text-decoration: none;
    }
    .an-jump a:hover { border-color: var(--mint); color: var(--mint); }
    .an-jump a svg { width: 15px; height: 15px; }

    .an-note { padding: 11px 13px; border-radius: 11px; background: #EAF1FB; color: #1A5C9A; font-size: 12.5px; line-height: 1.6; }
    .an-note.warn { background: #FEF6DC; color: #7A5A00; }
    .an-note b { display: block; margin-bottom: 2px; }
    .an-empty { padding: 24px 0; text-align: center; font-size: 12.5px; color: var(--gray-400); }

    @media (max-width: 1240px) { .an-cols-3 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 1180px) { .an-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 900px)  { .an-cols, .an-cols-3 { grid-template-columns: minmax(0, 1fr); } }
    @media (max-width: 620px)  { .an-stats { grid-template-columns: minmax(0, 1fr); } }
</style>

<div class="an-crumb">
    <a href="<?= url('admin-reports') ?>">Reports</a><span>›</span><b>Platform analytics</b>
</div>

<div class="ss-hd">
    <div>
        <h1>Platform analytics</h1>
        <p>Mentor performance, subjects and badges — all time. For headline figures over a date range, see the <a href="<?= url('admin-reports') ?>" style="color:var(--mint);font-weight:600;text-decoration:none;">overall summary</a>.</p>
    </div>
    <div class="ss-hd-actions">
        <form method="post" action="<?= url('cron-missed-sessions') ?>" style="margin:0;"
              onsubmit="return confirm('Close every approved session that ended more than <?= (int)PC_MISSED_GRACE_HOURS ?> hour<?= PC_MISSED_GRACE_HOURS === 1 ? '' : 's' ?> ago?\n\nIf both people joined the call it is marked completed. Otherwise it is recorded as missed by whoever did not join, and both people are told. This also runs every 30 minutes on its own.');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="an-run">
                <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="m16.5 16.5 4 4" /></svg>
                Detect missed sessions
            </button>
        </form>
        <form method="post" action="<?= url('admin-check-badges') ?>" style="margin:0;"
              onsubmit="return confirm('Recalculate every mentor score and award any badge that has been earned?\n\nMentors who earn one are notified by email.');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="an-run primary">
                <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="12" cy="9" r="5.5" /><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 13.5-1 7 4.5-2.4 4.5 2.4-1-7" /></svg>
                Recalculate scores &amp; badges
            </button>
        </form>
    </div>
</div>

<!-- ══════════ Figures ══════════ -->
<div class="an-stats">
    <?php foreach ([
        ['Active mentors', $active_mentors, $verified_mentors . ' verified of ' . $total_mentors . ' total', '#E6F5EE', '#17654B', 'users'],
        ['Active mentees', $active_mentees, $total_mentees . ' registered', '#EAF1FB', '#1A5C9A', 'users'],
        ['Sessions today', $sessions_today, $sessions_week . ' so far this week', '#EAF6FB', '#0087CF', 'cal'],
        ['Completed sessions', $sessions_total, $sessions_closed > 0 ? $completion_rate . '% of the ' . $sessions_closed . ' that closed' : 'None have closed yet', '#F1ECFA', '#5A3E96', 'check'],
    ] as [$k, $v, $s, $bg, $fg, $ico]): ?>
        <div class="ss-stat">
            <span class="ss-stat-ico" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= $ico === 'check' ? ss_icon('check') : rp_icon($ico) ?></span>
            <div style="min-width:0;">
                <div class="ss-stat-k"><?= $k ?></div>
                <div class="ss-stat-v"><?= number_format($v) ?></div>
                <div class="ss-stat-s"><?= htmlspecialchars($s) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="an-stats">
    <?php foreach ([
        ['Missed sessions', $sessions_missed, $sessions_cancelled . ' cancelled · ' . $sessions_rejected . ' declined', '#FBE5E1', '#A6301F', 'flag'],
        ['Pending requests', $sessions_pending, 'Waiting on a mentor to answer', '#FEF6DC', '#8A6400', 'clock'],
        ['Platform rating', $total_reviews > 0 ? number_format($platform_rating, 1) . ' / 5' : '—', $total_reviews > 0 ? 'From ' . number_format($total_reviews) . ' review' . ($total_reviews === 1 ? '' : 's') : 'Nobody has reviewed yet', '#FEF6DC', '#B7791F', 'star'],
        ['Badges awarded', $total_badges_awarded, $auto_badges_awarded . ' automatic · ' . ($total_badges_awarded - $auto_badges_awarded) . ' by hand', '#EAF6FB', '#00679E', 'quiz'],
    ] as [$k, $v, $s, $bg, $fg, $ico]): ?>
        <div class="ss-stat">
            <span class="ss-stat-ico" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= in_array($ico, ['clock', 'star'], true) ? ss_icon($ico) : rp_icon($ico) ?></span>
            <div style="min-width:0;">
                <div class="ss-stat-k"><?= $k ?></div>
                <div class="ss-stat-v"><?= is_int($v) ? number_format($v) : $v ?></div>
                <div class="ss-stat-s"><?= htmlspecialchars($s) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ══════════ Mentor performance ══════════ -->
<div class="an-full">
    <div class="ss-card">
        <h2>Mentor performance<a href="<?= url('leaderboard') ?>">Member leaderboard</a></h2>
        <p class="an-sub">
            Every active mentor, ranked by the score explained below. Highest first.
            <?php if ($scores_at): ?>
                Scores were last worked out on <?= date('M j, Y \a\t g:i A', strtotime($scores_at)) ?>.
            <?php endif; ?>
        </p>

        <?php if ($unscored > 0): ?>
            <div class="an-note warn" style="margin-bottom:14px;">
                <b><?= $unscored ?> verified mentor<?= $unscored === 1 ? ' has' : 's have' ?> no score yet.</b>
                Scores are only written when the recalculation above runs, so a mentor who joined since the
                last run shows zeros rather than a real figure.
            </div>
        <?php endif; ?>

        <?php if (empty($top_mentors)): ?>
            <p class="an-empty">No active mentors yet.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="an-lb">
                    <thead>
                        <tr>
                            <th style="width:34px;"></th>
                            <th>Mentor</th>
                            <th class="num">Rating</th>
                            <th class="num">Sessions</th>
                            <th class="num">Completion</th>
                            <th class="num">Feedback</th>
                            <th>Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_mentors as $i => $m):
                            $rank  = $i + 1;
                            $name  = trim($m['firstname'] . ' ' . $m['lastname']);
                            $score = (float)$m['rec_score'];
                            $eff   = (float)$m['effectiveness_score'];
                            $comp  = (float)$m['completion_rate'];
                            $cls   = fn(float $v) => $v >= 70 ? 'high' : ($v >= 40 ? 'mid' : 'low');
                        ?>
                            <tr>
                                <td><span class="an-rank <?= $rank <= 3 ? 'top' : '' ?>"><?= $rank ?></span></td>
                                <td>
                                    <span class="an-who">
                                        <span class="an-av">
                                            <?php if (!empty($m['profile_image'])): ?>
                                                <img src="<?= htmlspecialchars($m['profile_image']) ?>" alt="">
                                            <?php else: ?>
                                                <?= htmlspecialchars(strtoupper(substr($name, 0, 1))) ?>
                                            <?php endif; ?>
                                        </span>
                                        <span style="min-width:0;">
                                            <a href="<?= url('admin-user') ?>?id=<?= (int)$m['user_id'] ?>"><b><?= htmlspecialchars($name) ?></b></a>
                                            <span class="an-who-s"><?= (int)$m['review_count'] ?> review<?= (int)$m['review_count'] === 1 ? '' : 's' ?></span>
                                        </span>
                                    </span>
                                </td>
                                <td class="num">
                                    <?php if ((float)$m['avg_rating'] > 0): ?>
                                        <span style="color:#B7791F;">★</span> <?= number_format((float)$m['avg_rating'], 1) ?>
                                    <?php else: ?>
                                        <span style="color:var(--gray-300);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="num" style="font-weight:700;color:var(--gray-800);"><?= (int)$m['total_sessions'] ?></td>
                                <td class="num">
                                    <?php if ((int)$m['total_sessions'] > 0 || $comp > 0): ?>
                                        <span style="font-weight:600;color:<?= $comp >= 70 ? '#17654B' : '#A6301F' ?>;"><?= number_format($comp, 0) ?>%</span>
                                    <?php else: ?>
                                        <span style="color:var(--gray-300);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="num"><span class="an-pill <?= $cls($eff) ?>"><?= number_format($eff, 1) ?></span></td>
                                <td><span class="an-pill <?= $cls($score) ?>"><?= number_format($score, 1) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── How the score works ── -->
<div class="an-full">
    <div class="ss-card">
        <h2>How the score is worked out</h2>
        <p class="an-sub">
            Five measurements, fixed weights, adding to 100. No model and no guessing — every input below is a
            count or an average of rows in this database, and the weights are set in
            <code>App/services/MentorScoreService.php</code>.
        </p>
        <div class="an-w-grid">
            <?php foreach ($score_weights as [$label, $pct, $help, $col]): ?>
                <div class="an-w" style="border-top-color:<?= $col ?>;">
                    <span class="an-w-n"><?= $pct ?>%</span>
                    <span class="an-w-t"><b><?= $label ?></b><span><?= $help ?></span></span>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="an-sub" style="margin:16px 0 0;">
            The <b>Feedback</b> column in the table is a second blend of the same inputs, weighted toward what
            mentees wrote rather than how much a mentor has done — so a new mentor with strong reviews scores
            well there and lower overall.
        </p>
    </div>
</div>

<!-- ══════════ Twelve months ══════════ -->
<div class="an-cols">
    <?php
    /**
     * Both charts are the same shape, so they are drawn by one block rather
     * than two near-identical copies that can drift apart.
     */
    $charts = [
        ['Members joined', $reg_data,  '#1B6FD1', 'Mentees and mentors registering, by month. Admin accounts are not members and are left out.'],
        ['Sessions completed', $sess_data, '#17654B', 'Sessions that finished, counted in the month they were held.'],
    ];
    foreach ($charts as [$title, $data, $col, $blurb]):
        $peak = max(1, max(array_column($data, 'count')));
        $tot  = array_sum(array_column($data, 'count'));
        $W = 500; $H = 160; $padL = 26; $padB = 22; $padT = 10;
        $n = count($data);
        $slot = ($W - $padL - 8) / $n;
        $barW = min(24, $slot * 0.6);
        $cy = fn($v) => $H - $padB - ($v / $peak) * ($H - $padB - $padT);
    ?>
        <div class="ss-card">
            <h2><?= $title ?> — last 12 months</h2>
            <p class="an-sub"><?= $blurb ?></p>
            <?php if ($tot === 0): ?>
                <p class="an-empty">Nothing in the last twelve months.</p>
            <?php else: ?>
                <svg class="an-chart" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img"
                     aria-label="<?= htmlspecialchars($title) ?> per month, <?= $tot ?> in total over twelve months">
                    <?php for ($g = 0; $g <= 2; $g++):
                        $val = (int)round($peak * $g / 2); $y = $cy($val); ?>
                        <line x1="<?= $padL ?>" y1="<?= round($y, 1) ?>" x2="<?= $W - 6 ?>" y2="<?= round($y, 1) ?>" stroke="#EDEDED" stroke-width="1" />
                        <text class="an-axis" x="<?= $padL - 6 ?>" y="<?= round($y + 3, 1) ?>" text-anchor="end"><?= $val ?></text>
                    <?php endfor; ?>
                    <?php foreach ($data as $i => $d):
                        $x  = $padL + $i * $slot + ($slot - $barW) / 2;
                        $yT = $cy((int)$d['count']);
                        $mid = $x + $barW / 2;
                    ?>
                        <?php if ((int)$d['count'] > 0): ?>
                            <rect x="<?= round($x, 1) ?>" y="<?= round($yT, 1) ?>" width="<?= round($barW, 1) ?>"
                                  height="<?= round($H - $padB - $yT, 1) ?>" rx="3" fill="<?= $col ?>">
                                <title><?= htmlspecialchars($d['full']) ?>: <?= (int)$d['count'] ?></title>
                            </rect>
                            <text class="an-axis" x="<?= round($mid, 1) ?>" y="<?= round($yT - 4, 1) ?>" text-anchor="middle" style="font-weight:700;fill:<?= $col ?>;"><?= (int)$d['count'] ?></text>
                        <?php endif; ?>
                        <text class="an-axis" x="<?= round($mid, 1) ?>" y="<?= $H - 6 ?>" text-anchor="middle"><?= htmlspecialchars($d['label']) ?></text>
                    <?php endforeach; ?>
                </svg>
                <div class="an-legend"><span><i style="background:<?= $col ?>"></i><?= number_format($tot) ?> over the twelve months</span></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- ══════════ Three breakdowns ══════════ -->
<?php
/*
 * Three short lists side by side rather than one tall card next to a short
 * one. Each holds a comparable number of rows, so the row reads as a set
 * instead of leaving a hole under the shortest card.
 */
?>
<div class="an-cols-3">
    <div class="ss-card">
        <h2>Subjects booked<a href="<?= url('admin-sessions') ?>">All</a></h2>
        <p class="an-sub">Every session ever booked, by what it was for.</p>
        <?php if (empty($pop_subjects)): ?>
            <p class="an-empty">No sessions have been booked yet.</p>
        <?php else:
            $sub_max = max(array_column($pop_subjects, 'cnt'));
            foreach ($pop_subjects as $s): ?>
                <div class="an-bar">
                    <b title="<?= htmlspecialchars($s['subject']) ?>"><?= htmlspecialchars($s['subject']) ?></b>
                    <span class="an-bar-t"><i style="width:<?= round($s['cnt'] / $sub_max * 100) ?>%;background:#1B6FD1"></i></span>
                    <span class="an-bar-n"><?= (int)$s['cnt'] ?></span>
                </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="ss-card">
        <h2>Member accounts<a href="<?= url('admin-users') ?>">Manage</a></h2>
        <p class="an-sub">Admin accounts are excluded from both counts.</p>

        <p class="an-group">Standing</p>
        <?php
        $st_cols = ['Active' => '#17654B', 'Restricted' => '#B7791F', 'Blocked' => '#A6301F'];
        $st_tot  = max(1, array_sum($status_data));
        foreach ($status_data as $label => $count): ?>
            <div class="an-bar">
                <b><?= $label ?></b>
                <span class="an-bar-t"><i style="width:<?= round($count / $st_tot * 100) ?>%;background:<?= $st_cols[$label] ?>"></i></span>
                <span class="an-bar-n"><?= $count ?></span>
            </div>
        <?php endforeach; ?>

        <p class="an-group">Verification</p>
        <?php
        $vf_cols = ['Verified' => '#1B6FD1', 'Not verified' => '#9CA3AF'];
        $vf_tot  = max(1, array_sum($verif_data));
        foreach ($verif_data as $label => $count): ?>
            <div class="an-bar">
                <b><?= $label ?></b>
                <span class="an-bar-t"><i style="width:<?= round($count / $vf_tot * 100) ?>%;background:<?= $vf_cols[$label] ?>"></i></span>
                <span class="an-bar-n"><?= $count ?></span>
            </div>
        <?php endforeach; ?>
        <?php if ($verif_waiting > 0): ?>
            <p class="an-sub" style="margin:12px 0 0;">
                <?= $verif_waiting ?> submission<?= $verif_waiting === 1 ? ' is' : 's are' ?> waiting —
                <a href="<?= url('admin-users') ?>?tab=pending">open the queue</a>.
            </p>
        <?php endif; ?>
    </div>

    <div class="ss-card">
        <h2>Session outcomes<a href="<?= url('admin-sessions-reports') ?>">Reports</a></h2>
        <p class="an-sub">How every session ever booked ended up.</p>
        <?php
        $sess_statuses = [
            'Completed' => [$sessions_total,     '#17654B'],
            'Pending'   => [$sessions_pending,   '#B7791F'],
            'Missed'    => [$sessions_missed,    '#A6301F'],
            'Cancelled' => [$sessions_cancelled, '#9CA3AF'],
            'Declined'  => [$sessions_rejected,  '#6B7280'],
        ];
        $sess_ttl = max(1, array_sum(array_column($sess_statuses, 0)));
        foreach ($sess_statuses as $lbl => [$cnt, $col]): ?>
            <div class="an-bar">
                <b><?= $lbl ?></b>
                <span class="an-bar-t"><i style="width:<?= round($cnt / $sess_ttl * 100) ?>%;background:<?= $col ?>"></i></span>
                <span class="an-bar-n"><?= $cnt ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ══════════ Missed + badges ══════════ -->
<div class="an-cols">
    <?php
    /*
     * Both halves of the missed-session question in one card: who failed to
     * appear, and which mentor was booked. They used to be two cards in
     * different parts of the page, which put a one-row card beside a
     * seven-row one and left a hole under it.
     */
    $mb_labels = ['mentor' => ['The mentor', '#A6301F'], 'mentee' => ['The mentee', '#B7791F'], 'both' => ['Both of them', '#7C2417'], 'none' => ['Not recorded', '#9CA3AF']];
    $mb_total  = array_sum($missed_by);
    ?>
    <div class="ss-card">
        <h2>Missed sessions<a href="<?= url('admin-sessions') ?>?tab=missed">See the sessions</a></h2>
        <p class="an-sub"><?= $sessions_missed ?> session<?= $sessions_missed === 1 ? ' has' : 's have' ?> been marked missed, all time.</p>

        <?php if ($sessions_missed === 0): ?>
            <p class="an-empty">Nothing has been marked missed.</p>
        <?php else: ?>
            <p class="an-group">Who was absent</p>
            <?php foreach ($mb_labels as $k => [$label, $col]):
                $n = $missed_by[$k] ?? 0;
                if ($n === 0) continue; ?>
                <div class="an-bar">
                    <b><?= $label ?></b>
                    <span class="an-bar-t"><i style="width:<?= round($n / max(1, $mb_total) * 100) ?>%;background:<?= $col ?>"></i></span>
                    <span class="an-bar-n"><?= $n ?></span>
                </div>
            <?php endforeach; ?>

            <p class="an-group">Mentor who was booked</p>
            <?php
            $miss_max = $missed_mentors ? max(array_column($missed_mentors, 'cnt')) : 1;
            foreach ($missed_mentors as $mm): ?>
                <div class="an-bar">
                    <b><a href="<?= url('admin-user') ?>?id=<?= (int)$mm['user_id'] ?>"><?= htmlspecialchars(trim($mm['firstname'] . ' ' . $mm['lastname'])) ?></a></b>
                    <span class="an-bar-t"><i style="width:<?= round($mm['cnt'] / $miss_max * 100) ?>%;background:#A6301F"></i></span>
                    <span class="an-bar-n" style="color:#A6301F;"><?= (int)$mm['cnt'] ?></span>
                </div>
            <?php endforeach; ?>
            <p class="an-sub" style="margin:12px 0 0;">
                Appearing in that second list means the session was booked with that mentor, not that the mentor
                was the one who failed to appear — the split above is what says that.
            </p>
        <?php endif; ?>
    </div>

    <div class="ss-card">
        <h2>Badges awarded<a href="<?= url('admin-badges') ?>">Manage badges</a></h2>
        <p class="an-sub">What each badge takes to earn, and how many people hold it.</p>
        <?php foreach ($badge_rows as $b): ?>
            <div class="an-badge <?= (int)$b['cnt'] === 0 ? 'zero' : '' ?>">
                <span class="an-badge-i">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="12" cy="9" r="5.5" /><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 13.5-1 7 4.5-2.4 4.5 2.4-1-7" /></svg>
                </span>
                <span class="an-badge-t">
                    <b><?= htmlspecialchars($b['name']) ?><?= !(int)$b['is_active'] ? ' · retired' : '' ?></b>
                    <span><?= htmlspecialchars(an_criteria($b)) ?></span>
                </span>
                <span class="an-badge-n"><?= (int)$b['cnt'] ?></span>
            </div>
        <?php endforeach; ?>
        <?php if ($total_badges_awarded === 0): ?>
            <p class="an-sub" style="margin:14px 0 0;">Nothing has been awarded yet. Recalculating above will hand out every badge that has already been earned.</p>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════ Elsewhere ══════════ -->
<div class="ss-card">
    <h2>Where to go next</h2>
    <div class="an-jump">
        <a href="<?= url('admin-reports') ?>">
            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19V9m5 10V5m5 14v-7m5 7V8" /></svg>
            Overall summary
        </a>
        <a href="<?= url('admin-sessions-reports') ?>">
            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="15" rx="2.5" /><path stroke-linecap="round" d="M8 3v4M16 3v4M3.5 10h17" /></svg>
            Session reports
        </a>
        <a href="<?= url('admin-assessments-results') ?>">
            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><rect x="4" y="3.5" width="16" height="17" rx="2.5" /><path stroke-linecap="round" d="M8 9h8M8 13h8M8 17h4" /></svg>
            Assessment results
        </a>
        <a href="<?= url('admin-users') ?>">
            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="9" cy="8.5" r="3" /><path stroke-linecap="round" d="M3.5 20a5.5 5.5 0 0 1 11 0M16 5.6a3 3 0 0 1 0 5.8M17.5 14.4A5.5 5.5 0 0 1 20.5 20" /></svg>
            Users
        </a>
        <a href="<?= url('admin-settings-logs') ?>">
            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7.5V12l2.8 1.8" /></svg>
            Activity logs
        </a>
    </div>
</div>
