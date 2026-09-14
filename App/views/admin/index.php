<?php

/**
 * admin/index.php — the admin dashboard.
 *
 * Built to sit on one screen: the page itself never scrolls, and any panel
 * whose content can outgrow its box scrolls inside that box instead. Below
 * 1080px wide or 700px tall that stops being possible — twelve panels cannot
 * fit a phone — so the page falls back to scrolling there rather than
 * clipping half the content away.
 *
 * Every figure is a live count. Where the reference design showed a metric
 * this app does not record (workshop sessions, profile-edit history), the
 * panel shows what is actually recorded instead of inventing a number.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_admin();

date_default_timezone_set('Asia/Manila');

/* ── Range ────────────────────────────────────────────────────────────────
 * The reference design's date picker. These are the ranges the data can
 * actually answer for; "all" is the honest default for a young database.
 */
$RANGES = [
    'month' => ['This month',    "DATE_FORMAT(CURDATE(), '%Y-%m-01')", 'this month'],
    '30'    => ['Last 30 days',  "CURDATE() - INTERVAL 30 DAY",        'in 30 days'],
    'year'  => ['This year',     "DATE_FORMAT(CURDATE(), '%Y-01-01')", 'this year'],
    'all'   => ['All time',      null,                                  'in total'],
];
$range = isset($_GET['range'], $RANGES[$_GET['range']]) ? $_GET['range'] : 'all';
$since = $RANGES[$range][1];
$rangeNote = $RANGES[$range][2];

/** WHERE fragment for a date column, or '' when the range is all-time. */
function adm_since(string $col, ?string $since): string
{
    return $since === null ? '' : " AND $col >= $since";
}

$one = function (string $sql) use ($con): int {
    $r = $con->query($sql);
    return $r ? (int)$r->fetch_row()[0] : 0;
};

/* ── Headline counts ─────────────────────────────────────────────────── */
$total_users   = $one("SELECT COUNT(*) FROM users");
$total_mentees = $one("SELECT COUNT(*) FROM users WHERE role = 'mentee'");
$total_mentors = $one("SELECT COUNT(*) FROM users WHERE role = 'mentor'");
$total_admins  = $one("SELECT COUNT(*) FROM users WHERE role = 'admin'");
$total_sessions = $one("SELECT COUNT(*) FROM session_requests WHERE status = 'completed'");

$rating_row = $con->query("SELECT AVG(rating) a, COUNT(*) n FROM feedback")->fetch_assoc();
$avg_rating = (float)($rating_row['a'] ?? 0);
$rating_n   = (int)($rating_row['n'] ?? 0);

/* ── Month-over-month movement ────────────────────────────────────────────
 * Each card's trend compares this calendar month with last. A trend is only
 * shown when the previous month had something to compare against, so a young
 * database shows the figure and no arrow rather than a meaningless "+100%".
 */
$mstart = "DATE_FORMAT(CURDATE(), '%Y-%m-01')";
$lstart = "DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')";

function adm_trend(int $now, int $prev): ?array
{
    if ($prev <= 0) return null;
    $pct = (int)round((($now - $prev) / $prev) * 100);
    if ($pct === 0) return null;
    return ['up' => $pct > 0, 'label' => ($pct > 0 ? '+' : '') . $pct . '% from last month'];
}

$u_now  = $one("SELECT COUNT(*) FROM users WHERE created_at >= $mstart");
$u_prev = $one("SELECT COUNT(*) FROM users WHERE created_at >= $lstart AND created_at < $mstart");
$t_users = adm_trend($u_now, $u_prev);

$me_now  = $one("SELECT COUNT(*) FROM users WHERE role='mentee' AND created_at >= $mstart");
$me_prev = $one("SELECT COUNT(*) FROM users WHERE role='mentee' AND created_at >= $lstart AND created_at < $mstart");
$t_mentees = adm_trend($me_now, $me_prev);

$mo_now  = $one("SELECT COUNT(*) FROM users WHERE role='mentor' AND created_at >= $mstart");
$mo_prev = $one("SELECT COUNT(*) FROM users WHERE role='mentor' AND created_at >= $lstart AND created_at < $mstart");
$t_mentors = adm_trend($mo_now, $mo_prev);

$s_now  = $one("SELECT COUNT(*) FROM session_requests WHERE status='completed' AND session_date >= $mstart");
$s_prev = $one("SELECT COUNT(*) FROM session_requests WHERE status='completed' AND session_date >= $lstart AND session_date < $mstart");
$t_sessions = adm_trend($s_now, $s_prev);

$r_now  = $con->query("SELECT AVG(rating) a FROM feedback WHERE created_at >= $mstart")->fetch_assoc()['a'];
$r_prev = $con->query("SELECT AVG(rating) a FROM feedback WHERE created_at >= $lstart AND created_at < $mstart")->fetch_assoc()['a'];
$t_rating = null;
if ($r_now !== null && $r_prev !== null) {
    $d = (float)$r_now - (float)$r_prev;
    if (abs($d) >= 0.05) {
        $t_rating = ['up' => $d > 0, 'label' => ($d > 0 ? '+' : '−') . number_format(abs($d), 1) . ' from last month'];
    }
}

/* ── User growth, day by day across the selected range ────────────────── */
$growth_days = $range === 'year' ? 365 : ($range === 'all' ? 90 : 30);
$growth = [];
$g = $con->query("
    SELECT DATE(created_at) d, role, COUNT(*) c
    FROM users
    WHERE created_at >= CURDATE() - INTERVAL $growth_days DAY
    GROUP BY d, role
");
while ($row = $g->fetch_assoc()) {
    $d = $row['d'];
    $growth[$d] ??= ['mentee' => 0, 'mentor' => 0];
    if (isset($growth[$d][$row['role']])) $growth[$d][$row['role']] = (int)$row['c'];
}
// Fill every day so the line has an even x axis.
$series = [];
for ($i = $growth_days; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $series[] = [
        'date'   => $d,
        'mentee' => $growth[$d]['mentee'] ?? 0,
        'mentor' => $growth[$d]['mentor'] ?? 0,
    ];
}

/* ── Session distribution ─────────────────────────────────────────────────
 * From the availability slot each request was booked against. Slots that
 * have since been deleted cannot be typed, so they are their own slice
 * rather than being folded into one of the real ones.
 */
$dist = ['1v1' => 0, 'group' => 0, 'untyped' => 0];
$dq = $con->query("
    SELECT COALESCE(a.session_type, '') t, COUNT(*) c
    FROM session_requests sr
    LEFT JOIN availability a
           ON a.mentor_id = sr.mentor_id AND a.subject = sr.subject
          AND DATE(a.date) = DATE(sr.session_date)
          AND TIME(a.start_time) = TIME(sr.session_date)
    WHERE sr.status IN ('approved','completed')
    GROUP BY t
");
while ($row = $dq->fetch_assoc()) {
    $t = $row['t'];
    if ($t === '1v1') $dist['1v1'] += (int)$row['c'];
    elseif ($t === 'group') $dist['group'] += (int)$row['c'];
    else $dist['untyped'] += (int)$row['c'];
}
$dist_total = array_sum($dist);

/* ── User status ─────────────────────────────────────────────────────────
 * users.status plus the verified flag: an active-but-unverified account is
 * waiting on an admin, which is a different thing from a live account.
 */
$st = [
    'active'     => $one("SELECT COUNT(*) FROM users WHERE status='active' AND verified=1"),
    'pending'    => $one("SELECT COUNT(*) FROM users WHERE status='active' AND verified=0"),
    'restricted' => $one("SELECT COUNT(*) FROM users WHERE status='restricted'"),
    'blocked'    => $one("SELECT COUNT(*) FROM users WHERE status='blocked'"),
];
$st_total = array_sum($st);

/* ── Lists ────────────────────────────────────────────────────────────── */
$recent = $con->query("
    SELECT u.user_id, u.firstname, u.lastname, u.role, u.created_at,
           u.email, p.profile_image
    FROM users u
    LEFT JOIN profile p ON p.user_id = u.user_id
    ORDER BY u.created_at DESC
    LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

$upcoming = $con->query("
    SELECT sr.request_id, sr.subject, sr.session_date,
           CONCAT(mu.firstname,' ',mu.lastname) AS mentor_name,
           CONCAT(eu.firstname,' ',eu.lastname) AS mentee_name,
           COALESCE(a.session_type,'') AS stype,
           COALESCE(a.duration, 60)    AS duration
    FROM session_requests sr
    JOIN users mu ON mu.user_id = sr.mentor_id
    JOIN users eu ON eu.user_id = sr.mentee_id
    LEFT JOIN availability a
           ON a.mentor_id = sr.mentor_id AND a.subject = sr.subject
          AND DATE(a.date) = DATE(sr.session_date)
          AND TIME(a.start_time) = TIME(sr.session_date)
    WHERE sr.session_date >= NOW() AND sr.status IN ('pending','approved')
    ORDER BY sr.session_date ASC
    LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

/* System activity, assembled from the events the app actually records. */
$activity = [];
foreach ($con->query("SELECT firstname, lastname, role, created_at FROM users ORDER BY created_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC) as $r) {
    $activity[] = ['at' => $r['created_at'], 'kind' => 'join', 'title' => 'New ' . $r['role'] . ' registration',
        'body' => trim($r['firstname'] . ' ' . $r['lastname']) . ' joined as a ' . $r['role']];
}
foreach ($con->query("
    SELECT sr.subject, sr.completed_at, CONCAT(eu.firstname,' ',eu.lastname) AS who
    FROM session_requests sr JOIN users eu ON eu.user_id = sr.mentee_id
    WHERE sr.status='completed' AND sr.completed_at IS NOT NULL
    ORDER BY sr.completed_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC) as $r) {
    $activity[] = ['at' => $r['completed_at'], 'kind' => 'session', 'title' => 'Session completed',
        'body' => $r['subject'] . ' session with ' . $r['who']];
}
foreach ($con->query("
    SELECT f.rating, f.created_at, CONCAT(u.firstname,' ',u.lastname) AS who
    FROM feedback f JOIN users u ON u.user_id = f.mentee_id
    ORDER BY f.created_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC) as $r) {
    $activity[] = ['at' => $r['created_at'], 'kind' => 'star', 'title' => 'Feedback received',
        'body' => $r['who'] . ' gave a ' . rtrim(rtrim(number_format((float)$r['rating'], 1), '0'), '.') . '-star rating'];
}
foreach ($con->query("
    SELECT b.name, ub.awarded_at, CONCAT(u.firstname,' ',u.lastname) AS who
    FROM user_badges ub JOIN badges b ON b.badge_id = ub.badge_id JOIN users u ON u.user_id = ub.user_id
    ORDER BY ub.awarded_at DESC LIMIT 3")->fetch_all(MYSQLI_ASSOC) as $r) {
    $activity[] = ['at' => $r['awarded_at'], 'kind' => 'badge', 'title' => 'Badge awarded',
        'body' => $r['who'] . ' earned "' . $r['name'] . '"'];
}
usort($activity, fn($a, $b) => strtotime($b['at']) <=> strtotime($a['at']));
$activity = array_slice($activity, 0, 7);

/* Top subjects, as a share of all booked sessions. */
$subjects = $con->query("
    SELECT subject, COUNT(*) c FROM session_requests
    WHERE subject <> '' GROUP BY subject ORDER BY c DESC LIMIT 5
")->fetch_all(MYSQLI_ASSOC);
$subject_total = $one("SELECT COUNT(*) FROM session_requests WHERE subject <> ''");

/* Rating distribution. */
$stars = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
foreach ($con->query("SELECT ROUND(rating) r, COUNT(*) c FROM feedback GROUP BY r")->fetch_all(MYSQLI_ASSOC) as $r) {
    $k = (int)$r['r'];
    if (isset($stars[$k])) $stars[$k] = (int)$r['c'];
}

$pending_verif = $one("SELECT COUNT(*) FROM user_verifications WHERE status = 'pending'");
$open_reports  = $one("SELECT COUNT(*) FROM reports WHERE status = 'pending'");

/** "2 mins ago" from a datetime. */
function adm_ago(?string $when): string
{
    if (!$when) return '';
    $s = time() - strtotime($when);
    if ($s < 60)     return $s <= 1 ? 'just now' : $s . ' secs ago';
    if ($s < 3600)   return floor($s / 60) . ' min' . (floor($s / 60) === 1.0 ? '' : 's') . ' ago';
    if ($s < 86400)  return floor($s / 3600) . ' hour' . (floor($s / 3600) === 1.0 ? '' : 's') . ' ago';
    if ($s < 2592000) return floor($s / 86400) . ' day' . (floor($s / 86400) === 1.0 ? '' : 's') . ' ago';
    return date('M j, Y', strtotime($when));
}

/** One donut arc set: [[value, colour, label], ...] -> svg circles. */
function adm_donut(array $slices, int $total): string
{
    if ($total <= 0) return '';
    $C = 2 * M_PI * 42;      // r = 42
    $offset = 0.0;
    $out = '';
    foreach ($slices as [$value, $colour]) {
        if ($value <= 0) continue;
        $len = ($value / $total) * $C;
        $out .= '<circle cx="60" cy="60" r="42" fill="none" stroke="' . $colour . '" stroke-width="16"'
            . ' stroke-dasharray="' . round($len, 2) . ' ' . round($C - $len, 2) . '"'
            . ' stroke-dashoffset="' . round(-$offset, 2) . '" transform="rotate(-90 60 60)"/>';
        $offset += $len;
    }
    return $out;
}

$current_page = 'index';
include 'layout.php';
?>

<style>
    /* The page is a fixed screen, not a document: <main> stops scrolling and
       becomes the grid, and the panels that hold lists scroll inside. */
    main.flex-1 {
        overflow: hidden !important;
        padding: 16px 18px 18px !important;
        display: flex;
        flex-direction: column;
        gap: 12px;
        min-height: 0;
    }

    .ad-hd { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
    .ad-hd h1 { font-size: 25px; font-weight: 700; color: var(--forest); letter-spacing: -.02em; margin: 0; }
    .ad-hd p { margin: 2px 0 0; font-size: 13px; color: var(--gray-400); }

    .ad-range {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        padding: 8px 12px;
        border: 1px solid var(--gray-200);
        border-radius: 10px;
        background: #fff;
    }

    .ad-range svg { width: 16px; height: 16px; color: var(--gray-400); }

    .ad-range select {
        border: none;
        background: none;
        font-family: inherit;
        font-size: 13.5px;
        font-weight: 500;
        color: var(--gray-800);
        cursor: pointer;
        outline: none;
    }

    /* ── Stat row ── */
    .ad-stats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; }

    .ad-stat {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 13px 14px;
        border-radius: 14px;
        border: 1px solid transparent;
    }

    .ad-stat-ico { flex: 0 0 42px; width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; }
    .ad-stat-ico svg { width: 20px; height: 20px; }
    .ad-stat-k { font-size: 12.5px; color: var(--gray-500); font-weight: 500; }
    .ad-stat-v { font-family: 'DM Serif Display', serif; font-size: 26px; line-height: 1.1; color: var(--gray-900); }
    .ad-stat-t { display: inline-flex; align-items: center; gap: 4px; margin-top: 2px; font-size: 11.5px; }
    .ad-stat-t svg { width: 11px; height: 11px; }
    .ad-up { color: #17654B; }
    .ad-down { color: #A6301F; }
    .ad-flat { color: var(--gray-400); }

    /* ── Panel grid: two rows of three, then a third ── */
    .ad-grid {
        flex: 1;
        min-height: 0;
        display: grid;
        grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr) minmax(0, 1fr);
        grid-template-rows: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr);
        gap: 12px;
    }

    .ad-card {
        background: #fff;
        border: 1px solid var(--gray-100);
        border-radius: 14px;
        padding: 13px 15px;
        display: flex;
        flex-direction: column;
        min-height: 0;
        box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
    }

    .ad-ct {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 10px;
        font-size: 13.5px;
        font-weight: 600;
        color: var(--gray-900);
        flex-shrink: 0;
    }

    .ad-ct > svg { width: 16px; height: 16px; color: var(--mint); flex: 0 0 16px; }
    .ad-ct .ad-more { margin-left: auto; font-size: 12px; font-weight: 500; color: var(--mint); text-decoration: none; }
    .ad-ct .ad-more:hover { text-decoration: underline; }

    .ad-body { flex: 1; min-height: 0; overflow-y: auto; overscroll-behavior: contain; }
    .ad-body::-webkit-scrollbar { width: 5px; }
    .ad-body::-webkit-scrollbar-thumb { background: var(--gray-200); border-radius: 99px; }

    /* ── Rows inside panels ── */
    .ad-row { display: flex; align-items: center; gap: 10px; padding: 7px 0; border-bottom: 1px solid var(--gray-50); }
    .ad-row:last-child { border-bottom: none; }

    .ad-av {
        flex: 0 0 32px; width: 32px; height: 32px; border-radius: 50%;
        display: grid; place-items: center; font-size: 11.5px; font-weight: 700;
        background: var(--mint-faint); color: var(--mint-deep, #00539B); overflow: hidden;
    }

    .ad-av img { width: 100%; height: 100%; object-fit: cover; }
    .ad-who { flex: 1; min-width: 0; }
    .ad-n { font-size: 12.5px; font-weight: 600; color: var(--gray-800); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ad-s { font-size: 11px; color: var(--gray-400); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ad-when { font-size: 11px; color: var(--gray-400); white-space: nowrap; flex-shrink: 0; }

    .ad-pill { padding: 2px 8px; border-radius: 99px; font-size: 10.5px; font-weight: 600; white-space: nowrap; }
    .ad-pill-mentee { background: var(--mint-faint); color: #00539B; }
    .ad-pill-mentor { background: #E6F4EE; color: #17654B; }
    .ad-pill-admin { background: #EFEDFC; color: #4A3FB8; }

    /* Upcoming sessions: a date chip and a coloured rule per row. */
    .ad-date {
        flex: 0 0 38px; width: 38px; border-radius: 9px; background: var(--gray-50);
        text-align: center; padding: 4px 0;
    }

    .ad-date b { display: block; font-size: 13px; color: var(--gray-800); line-height: 1.1; }
    .ad-date span { font-size: 9.5px; text-transform: uppercase; letter-spacing: .06em; color: var(--gray-400); }
    .ad-bar { flex: 0 0 3px; align-self: stretch; border-radius: 99px; }

    /* Activity timeline */
    .ad-act { display: flex; gap: 10px; padding: 7px 0; }
    .ad-act-ico { flex: 0 0 28px; width: 28px; height: 28px; border-radius: 8px; display: grid; place-items: center; }
    .ad-act-ico svg { width: 14px; height: 14px; }

    /* Bars */
    .ad-bar-row { display: flex; align-items: center; gap: 10px; padding: 5px 0; font-size: 12px; }
    .ad-bar-label { flex: 0 0 108px; color: var(--gray-600); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ad-bar-track { flex: 1; height: 8px; border-radius: 99px; background: var(--gray-100); overflow: hidden; }
    .ad-bar-fill { height: 100%; border-radius: 99px; }
    .ad-bar-pct { flex: 0 0 34px; text-align: right; font-weight: 600; color: var(--gray-700); }

    /* Donut + legend */
    .ad-donut { display: flex; align-items: center; gap: 14px; height: 100%; }
    .ad-donut svg { flex: 0 0 118px; width: 118px; height: 118px; }
    .ad-donut-mid { text-anchor: middle; }
    .ad-donut-mid .n { font-family: 'DM Serif Display', serif; font-size: 20px; fill: var(--gray-900); }
    .ad-donut-mid .k { font-size: 8px; fill: var(--gray-400); }
    .ad-legend { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 7px; }
    .ad-leg { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--gray-600); }
    .ad-dot { flex: 0 0 9px; width: 9px; height: 9px; border-radius: 50%; }
    .ad-leg b { margin-left: auto; color: var(--gray-800); font-weight: 600; }

    /* Quick actions */
    .ad-acts { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; }

    .ad-act-btn {
        display: flex; align-items: center; gap: 9px; padding: 11px 12px;
        border: 1px solid var(--gray-100); border-radius: 11px;
        text-decoration: none; background: #fff;
    }

    .ad-act-btn:hover { border-color: var(--mint-soft); background: var(--mint-faint); }
    .ad-act-btn span { flex: 0 0 30px; width: 30px; height: 30px; border-radius: 9px; display: grid; place-items: center; }
    .ad-act-btn svg { width: 15px; height: 15px; }
    .ad-act-btn b { display: block; font-size: 12.5px; font-weight: 600; color: var(--gray-800); }
    .ad-act-btn i { display: block; font-style: normal; font-size: 10.5px; color: var(--gray-400); }

    .ad-empty { display: grid; place-items: center; height: 100%; text-align: center; color: var(--gray-300); font-size: 12px; padding: 12px; }

    /* Feedback overview split */
    .ad-fb { display: flex; gap: 14px; height: 100%; }
    .ad-fb-score { flex: 0 0 90px; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .ad-fb-score b { font-family: 'DM Serif Display', serif; font-size: 30px; color: var(--gray-900); line-height: 1; }
    .ad-fb-score span { font-size: 11px; color: var(--gray-400); margin-top: 3px; text-align: center; }
    .ad-fb-bars { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; }

    /* Twelve panels cannot fit a small screen; let it scroll there. */
    @media (max-width: 1080px), (max-height: 700px) {
        main.flex-1 { overflow-y: auto !important; }
        .ad-grid { grid-template-columns: minmax(0, 1fr); grid-template-rows: none; }
        .ad-card { min-height: 220px; }
        .ad-stats { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); }
    }
</style>

<div class="ad-hd">
    <div>
        <h1>Admin Dashboard</h1>
        <p>Overview of your PeerConnect community</p>
    </div>
    <form method="get" class="ad-range">
        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
            <rect x="4" y="5" width="16" height="16" rx="3" />
            <path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16" />
        </svg>
        <label for="ad-range" class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">Date range</label>
        <select id="ad-range" name="range" onchange="this.form.submit()">
            <?php foreach ($RANGES as $key => $r): ?>
                <option value="<?= $key ?>" <?= $range === $key ? 'selected' : '' ?>><?= htmlspecialchars($r[0]) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<!-- ══════════ Stat row ══════════ -->
<div class="ad-stats">
    <?php
    $arrow = '<svg fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19V5m0 0-6 6m6-6 6 6"/></svg>';
    // [label, value, trend, card fill, card border, icon colour, icon path]
    $cards = [
        ['Total Users', number_format($total_users), $t_users, '#EAF2FE', '#CBDFF8', '#1B6FD1',
            '<path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m9-4a4 4 0 11-8 0 4 4 0 018 0z"/>'],
        ['Mentees', number_format($total_mentees), $t_mentees, '#E6F5EE', '#BFE2D1', '#17654B',
            '<path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/>'],
        ['Mentors', number_format($total_mentors), $t_mentors, '#EFEDFC', '#D6D0F5', '#4A3FB8',
            '<path stroke-linecap="round" stroke-linejoin="round" d="m12 4 9 5-9 5-9-5 9-5Z"/><path stroke-linecap="round" d="M7 11.5V16c0 1.4 2.2 2.5 5 2.5s5-1.1 5-2.5v-4.5"/>'],
        ['Total Sessions', number_format($total_sessions), $t_sessions, '#FBF0D4', '#F0DDA4', '#9A7100',
            '<rect x="4" y="5" width="16" height="16" rx="3"/><path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16"/>'],
        ['Average Rating', $rating_n ? number_format($avg_rating, 2) : '—', $t_rating, '#FBE5E1', '#F3C9C0', '#A6301F',
            '<path d="m12 3.6 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.3-4.1 5.9-.9L12 3.6Z"/>'],
    ];
    foreach ($cards as [$label, $value, $trend, $bg, $bd, $fg, $path]):
    ?>
        <div class="ad-stat" style="background:<?= $bg ?>;border-color:<?= $bd ?>;">
            <span class="ad-stat-ico" style="background:#fff;color:<?= $fg ?>;">
                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><?= $path ?></svg>
            </span>
            <div style="min-width:0;">
                <div class="ad-stat-k"><?= $label ?></div>
                <div class="ad-stat-v"><?= $value ?></div>
                <?php if ($trend): ?>
                    <span class="ad-stat-t <?= $trend['up'] ? 'ad-up' : 'ad-down' ?>"
                        style="<?= $trend['up'] ? '' : 'display:inline-flex;' ?>">
                        <span style="display:inline-flex;<?= $trend['up'] ? '' : 'transform:rotate(180deg);' ?>"><?= $arrow ?></span>
                        <?= htmlspecialchars($trend['label']) ?>
                    </span>
                <?php elseif ($label === 'Average Rating' && $rating_n): ?>
                    <span class="ad-stat-t ad-flat">From <?= $rating_n ?> review<?= $rating_n === 1 ? '' : 's' ?></span>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ══════════ Panels ══════════ -->
<div class="ad-grid">

    <!-- User growth -->
    <div class="ad-card">
        <div class="ad-ct">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" d="M5 20V11M12 20V4M19 20v-6" /></svg>
            User Growth
            <span style="margin-left:auto;display:inline-flex;gap:12px;font-size:11px;font-weight:500;color:var(--gray-500);">
                <span style="display:inline-flex;align-items:center;gap:5px;"><i class="ad-dot" style="background:#1B6FD1;"></i>Mentees</span>
                <span style="display:inline-flex;align-items:center;gap:5px;"><i class="ad-dot" style="background:#17654B;"></i>Mentors</span>
            </span>
        </div>
        <div class="ad-body" style="overflow:hidden;">
            <?php
            $maxY = 1;
            foreach ($series as $pt) $maxY = max($maxY, $pt['mentee'], $pt['mentor']);
            $W = 620;
            $H = 150;
            $n = max(1, count($series) - 1);
            $px = fn($i) => round(($i / $n) * ($W - 34) + 30, 1);
            $py = fn($v) => round($H - 20 - ($v / $maxY) * ($H - 34), 1);
            $line = function (string $key) use ($series, $px, $py) {
                $d = '';
                foreach ($series as $i => $pt) $d .= ($i ? 'L' : 'M') . $px($i) . ' ' . $py($pt[$key]) . ' ';
                return trim($d);
            };
            $area = fn(string $key) => $line($key) . ' L' . $px($n) . ' ' . ($H - 20) . ' L' . $px(0) . ' ' . ($H - 20) . ' Z';
            ?>
            <svg viewBox="0 0 <?= $W ?> <?= $H ?>" preserveAspectRatio="none" style="width:100%;height:100%;" role="img"
                aria-label="New mentee and mentor registrations per day">
                <?php for ($i = 0; $i <= 3; $i++):
                    $v = round($maxY * $i / 3);
                    $y = $py($v); ?>
                    <line x1="30" y1="<?= $y ?>" x2="<?= $W - 4 ?>" y2="<?= $y ?>" stroke="#EDEDED" stroke-width="1" />
                    <text x="24" y="<?= $y + 3 ?>" text-anchor="end" font-size="9" fill="#9A9EA6"><?= $v ?></text>
                <?php endfor; ?>
                <path d="<?= $area('mentee') ?>" fill="#1B6FD1" opacity=".10" />
                <path d="<?= $line('mentee') ?>" fill="none" stroke="#1B6FD1" stroke-width="2" stroke-linejoin="round" />
                <path d="<?= $line('mentor') ?>" fill="none" stroke="#17654B" stroke-width="2" stroke-linejoin="round" />
                <?php
                $ticks = 5;
                for ($t = 0; $t < $ticks; $t++):
                    $i = (int)round($t * $n / ($ticks - 1));
                    $lbl = date('M j', strtotime($series[$i]['date'])); ?>
                    <text x="<?= $px($i) ?>" y="<?= $H - 5 ?>" text-anchor="middle" font-size="9" fill="#9A9EA6"><?= $lbl ?></text>
                <?php endfor; ?>
            </svg>
        </div>
    </div>

    <!-- Session distribution -->
    <div class="ad-card">
        <div class="ad-ct">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 3v9l6.4 6.4" /></svg>
            Session Distribution
        </div>
        <div class="ad-body" style="overflow:hidden;">
            <?php if ($dist_total > 0): ?>
                <div class="ad-donut">
                    <svg viewBox="0 0 120 120" aria-hidden="true">
                        <circle cx="60" cy="60" r="42" fill="none" stroke="#F1F3F7" stroke-width="16" />
                        <?= adm_donut([[$dist['1v1'], '#1B6FD1'], [$dist['group'], '#17654B'], [$dist['untyped'], '#C7CDDA']], $dist_total) ?>
                        <g class="ad-donut-mid">
                            <text x="60" y="59" class="n"><?= $dist_total ?></text>
                            <text x="60" y="71" class="k">SESSIONS</text>
                        </g>
                    </svg>
                    <div class="ad-legend">
                        <?php foreach ([['1-on-1', $dist['1v1'], '#1B6FD1'], ['Group', $dist['group'], '#17654B'], ['Slot removed', $dist['untyped'], '#C7CDDA']] as [$l, $v, $c]): ?>
                            <div class="ad-leg"><i class="ad-dot" style="background:<?= $c ?>;"></i><?= $l ?><b><?= $dist_total ? round($v / $dist_total * 100) : 0 ?>%</b></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="ad-empty">No booked sessions yet.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- User status -->
    <div class="ad-card">
        <div class="ad-ct">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7.5 3.2v5.1c0 4.3-3 8.2-7.5 9.7-4.5-1.5-7.5-5.4-7.5-9.7V6.2L12 3Z" /></svg>
            User Status
        </div>
        <div class="ad-body" style="overflow:hidden;">
            <div class="ad-donut">
                <svg viewBox="0 0 120 120" aria-hidden="true">
                    <circle cx="60" cy="60" r="42" fill="none" stroke="#F1F3F7" stroke-width="16" />
                    <?= adm_donut([[$st['active'], '#17654B'], [$st['pending'], '#E5A800'], [$st['restricted'], '#1B6FD1'], [$st['blocked'], '#A6301F']], $st_total) ?>
                    <g class="ad-donut-mid">
                        <text x="60" y="59" class="n"><?= $st_total ?></text>
                        <text x="60" y="71" class="k">USERS</text>
                    </g>
                </svg>
                <div class="ad-legend">
                    <?php foreach ([['Active', $st['active'], '#17654B'], ['Pending', $st['pending'], '#E5A800'], ['Restricted', $st['restricted'], '#1B6FD1'], ['Blocked', $st['blocked'], '#A6301F']] as [$l, $v, $c]): ?>
                        <div class="ad-leg"><i class="ad-dot" style="background:<?= $c ?>;"></i><?= $l ?><b><?= $st_total ? round($v / $st_total * 100) : 0 ?>%</b></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent registrations -->
    <div class="ad-card">
        <div class="ad-ct">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6" /><path stroke-linecap="round" d="M5 20c.7-3.5 3.5-5.4 7-5.4s6.3 1.9 7 5.4" /></svg>
            Recent Registrations
            <a class="ad-more" href="<?= url('admin-users') ?>">View all</a>
        </div>
        <div class="ad-body">
            <?php if (!$recent): ?>
                <div class="ad-empty">No accounts yet.</div>
            <?php else: foreach ($recent as $u):
                    $nm = trim($u['firstname'] . ' ' . $u['lastname']);
                    $role = $u['role'] ?: 'member'; ?>
                    <div class="ad-row">
                        <span class="ad-av">
                            <?php if (!empty($u['profile_image'])): ?>
                                <img src="<?= htmlspecialchars($u['profile_image']) ?>" alt="">
                            <?php else: ?><?= htmlspecialchars(strtoupper(substr($u['firstname'], 0, 1) . substr($u['lastname'], 0, 1))) ?><?php endif; ?>
                        </span>
                        <span class="ad-who">
                            <span class="ad-n"><?= htmlspecialchars($nm) ?></span>
                            <span class="ad-s"><?= htmlspecialchars($u['email'] ?? '—') ?></span>
                        </span>
                        <span class="ad-pill ad-pill-<?= htmlspecialchars($role) ?>"><?= htmlspecialchars(ucfirst($role)) ?></span>
                        <span class="ad-when"><?= adm_ago($u['created_at']) ?></span>
                    </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Upcoming sessions -->
    <div class="ad-card">
        <div class="ad-ct">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="16" rx="3" /><path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16" /></svg>
            Upcoming Sessions
        </div>
        <div class="ad-body">
            <?php if (!$upcoming): ?>
                <div class="ad-empty">Nothing scheduled ahead.</div>
            <?php else: foreach ($upcoming as $s):
                    $ts = strtotime($s['session_date']);
                    $end = $ts + ((int)$s['duration'] * 60);
                    $isGroup = $s['stype'] === 'group'; ?>
                    <div class="ad-row">
                        <span class="ad-date"><b><?= date('j', $ts) ?></b><span><?= date('M', $ts) ?></span></span>
                        <i class="ad-bar" style="background:<?= $isGroup ? '#17654B' : '#1B6FD1' ?>;"></i>
                        <span class="ad-who">
                            <span class="ad-n"><?= htmlspecialchars($s['subject']) ?></span>
                            <span class="ad-s"><?= $isGroup ? 'Group' : '1-on-1' ?> &middot; <?= htmlspecialchars($s['mentor_name']) ?> &amp; <?= htmlspecialchars($s['mentee_name']) ?></span>
                        </span>
                        <span class="ad-when"><?= date('g:i A', $ts) ?> &ndash; <?= date('g:i A', $end) ?></span>
                    </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- System activity -->
    <div class="ad-card">
        <div class="ad-ct">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12h4l2.5-7 5 14L17 12h4" /></svg>
            System Activity
        </div>
        <div class="ad-body">
            <?php if (!$activity): ?>
                <div class="ad-empty">Nothing has happened yet.</div>
            <?php else:
                $tint = [
                    'join'    => ['#EAF2FE', '#1B6FD1', '<path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/>'],
                    'session' => ['#E6F5EE', '#17654B', '<path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.5 9 17l10.5-10"/>'],
                    'star'    => ['#FBF0D4', '#9A7100', '<path d="m12 3.6 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.3-4.1 5.9-.9L12 3.6Z"/>'],
                    'badge'   => ['#EFEDFC', '#4A3FB8', '<circle cx="12" cy="9" r="5.5"/><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 13.5-1 7 4.5-2.4 4.5 2.4-1-7"/>'],
                ];
                foreach ($activity as $a):
                    [$bg, $fg, $ico] = $tint[$a['kind']]; ?>
                    <div class="ad-act">
                        <span class="ad-act-ico" style="background:<?= $bg ?>;color:<?= $fg ?>;">
                            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><?= $ico ?></svg>
                        </span>
                        <span class="ad-who">
                            <span class="ad-n"><?= htmlspecialchars($a['title']) ?></span>
                            <span class="ad-s"><?= htmlspecialchars($a['body']) ?></span>
                        </span>
                        <span class="ad-when"><?= adm_ago($a['at']) ?></span>
                    </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Top subjects -->
    <div class="ad-card">
        <div class="ad-ct">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.5S9.5 4 5 4v13c4.5 0 7 2.5 7 2.5s2.5-2.5 7-2.5V4c-4.5 0-7 2.5-7 2.5Z" /></svg>
            Top Subjects
        </div>
        <div class="ad-body">
            <?php if (!$subjects): ?>
                <div class="ad-empty">No sessions booked yet.</div>
            <?php else:
                $palette = ['#1B6FD1', '#17654B', '#4A3FB8', '#9A7100', '#A6301F'];
                foreach ($subjects as $i => $s):
                    $pct = $subject_total ? round($s['c'] / $subject_total * 100) : 0; ?>
                    <div class="ad-bar-row">
                        <span class="ad-bar-label" title="<?= htmlspecialchars($s['subject']) ?>"><?= htmlspecialchars($s['subject']) ?></span>
                        <span class="ad-bar-track"><i class="ad-bar-fill" style="display:block;width:<?= $pct ?>%;background:<?= $palette[$i % 5] ?>;"></i></span>
                        <span class="ad-bar-pct"><?= $pct ?>%</span>
                    </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Feedback overview -->
    <div class="ad-card">
        <div class="ad-ct">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="m12 3.6 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.3-4.1 5.9-.9L12 3.6Z" /></svg>
            Feedback Overview
            <a class="ad-more" href="<?= url('admin-analytics') ?>">View all</a>
        </div>
        <div class="ad-body" style="overflow:hidden;">
            <?php if ($rating_n === 0): ?>
                <div class="ad-empty">No reviews yet.</div>
            <?php else: ?>
                <div class="ad-fb">
                    <div class="ad-fb-score">
                        <b><?= number_format($avg_rating, 2) ?></b>
                        <span>Average<br>from <?= $rating_n ?> review<?= $rating_n === 1 ? '' : 's' ?></span>
                    </div>
                    <div class="ad-fb-bars">
                        <?php foreach ([5, 4, 3, 2, 1] as $k):
                            $pct = $rating_n ? round($stars[$k] / $rating_n * 100) : 0; ?>
                            <div class="ad-bar-row">
                                <span class="ad-bar-label" style="flex:0 0 48px;"><?= $k ?> star<?= $k === 1 ? '' : 's' ?></span>
                                <span class="ad-bar-track"><i class="ad-bar-fill" style="display:block;width:<?= $pct ?>%;background:#E5A800;"></i></span>
                                <span class="ad-bar-pct"><?= $pct ?>%</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="ad-card">
        <div class="ad-ct">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 3 5 13h6l-1 8 8-10h-6l1-8Z" /></svg>
            Quick Actions
        </div>
        <div class="ad-body" style="overflow:hidden;">
            <div class="ad-acts">
                <a class="ad-act-btn" href="<?= url('admin-users') ?>">
                    <span style="background:#EAF2FE;color:#1B6FD1;"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m9-4a4 4 0 11-8 0 4 4 0 018 0z" /></svg></span>
                    <span style="flex:1;background:none;width:auto;height:auto;display:block;"><b>Manage Users</b><i><?= $total_users ?> accounts</i></span>
                </a>
                <a class="ad-act-btn" href="<?= url('admin-verify') ?>">
                    <span style="background:#E6F5EE;color:#17654B;"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg></span>
                    <span style="flex:1;background:none;width:auto;height:auto;display:block;"><b>Verification</b><i><?= $pending_verif ?> waiting</i></span>
                </a>
                <a class="ad-act-btn" href="<?= url('admin-reports') ?>">
                    <span style="background:#EFEDFC;color:#4A3FB8;"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" d="M5 20V11M12 20V4M19 20v-6" /></svg></span>
                    <span style="flex:1;background:none;width:auto;height:auto;display:block;"><b>Reports</b><i>Overall summary</i></span>
                </a>
                <a class="ad-act-btn" href="<?= url('admin-badges') ?>">
                    <span style="background:#FBF0D4;color:#9A7100;"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="9" r="5.5" /><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 13.5-1 7 4.5-2.4 4.5 2.4-1-7" /></svg></span>
                    <span style="flex:1;background:none;width:auto;height:auto;display:block;"><b>Badges</b><i>Award and manage</i></span>
                </a>
            </div>
        </div>
    </div>
</div>

<?php include 'admin_footer.php'; ?>
