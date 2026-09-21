<?php

/**
 * admin/badges.php — Content › Badges & Achievements.
 *
 * Badges are the platform's automatic recognition: three of the five criteria
 * types are checked and awarded by action_check_badges.php without anyone
 * pressing anything, and the other two an admin hands out. This screen is where
 * they are written, switched on and off, and given out by hand.
 *
 * Every figure is a count of rows in `badges` and `user_badges`. There is no
 * "up 18% from last month" here: nothing records how many badges existed a
 * month ago, so that comparison could only be invented.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/award_data.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$current_page = 'admin-badges';
$csrf = csrf_token();

/* ── Filters ──────────────────────────────────────────────────────────── */
$tab      = in_array($_GET['tab'] ?? '', ['active', 'inactive'], true) ? $_GET['tab'] : 'all';
$q        = trim((string)($_GET['q'] ?? ''));
$critFil  = array_key_exists($_GET['crit'] ?? '', aw_criteria_types()) ? $_GET['crit'] : '';
$perPage  = aw_per_page((int)($_GET['per'] ?? 10), [10, 20, 50]);
$page     = max(1, (int)($_GET['p'] ?? 1));

$where = [];
$types = '';
$args  = [];

if ($tab === 'active')   $where[] = 'b.is_active = 1';
if ($tab === 'inactive') $where[] = 'b.is_active = 0';
if ($q !== '')     { $where[] = '(b.name LIKE ? OR b.description LIKE ?)'; $types .= 'ss'; $args[] = "%$q%"; $args[] = "%$q%"; }
if ($critFil !== '') { $where[] = 'b.criteria_type = ?'; $types .= 's'; $args[] = $critFil; }

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countSql = "SELECT COUNT(*) FROM badges b $whereSql";
if ($types !== '') {
    $cs = $con->prepare($countSql);
    $cs->bind_param($types, ...$args);
    $cs->execute();
    $total = (int)$cs->get_result()->fetch_row()[0];
    $cs->close();
} else {
    $total = (int)$con->query($countSql)->fetch_row()[0];
}

$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$listSql = "
    SELECT b.*, COUNT(ub.user_badge_id) AS holders
      FROM badges b
      LEFT JOIN user_badges ub ON ub.badge_id = b.badge_id
    $whereSql
     GROUP BY b.badge_id
     ORDER BY b.is_active DESC, holders DESC, b.badge_id ASC
     LIMIT $perPage OFFSET $offset";

if ($types !== '') {
    $ls = $con->prepare($listSql);
    $ls->bind_param($types, ...$args);
    $ls->execute();
    $badges = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
    $ls->close();
} else {
    $badges = $con->query($listSql)->fetch_all(MYSQLI_ASSOC);
}

/* ── Figures ──────────────────────────────────────────────────────────── */
$one = function (string $sql) use ($con) {
    $r = $con->query($sql);
    return $r ? (int)$r->fetch_row()[0] : 0;
};

$totalBadges  = $one("SELECT COUNT(*) FROM badges");
$activeBadges = $one("SELECT COUNT(*) FROM badges WHERE is_active = 1");
$earned       = $one("SELECT COUNT(*) FROM user_badges");
$holders      = $one("SELECT COUNT(DISTINCT user_id) FROM user_badges");
$autoEarned   = $one("SELECT COUNT(*) FROM user_badges WHERE awarded_by IS NULL");
$mentorTotal  = $one("SELECT COUNT(*) FROM users WHERE role = 'mentor' AND status = 'active'");

// Every badge nobody holds — asked of the whole table, not of $badges, which
// is only the page currently on screen and would under-count once the list
// runs past one page.
$neverEarned  = $one("
    SELECT COUNT(*) FROM badges b
     WHERE NOT EXISTS (SELECT 1 FROM user_badges ub WHERE ub.badge_id = b.badge_id)");

/* Which kinds of badge people actually hold. */
$mix = [];
$mq = $con->query("
    SELECT b.criteria_type AS k, COUNT(*) n
      FROM badges b GROUP BY b.criteria_type ORDER BY n DESC");
while ($row = $mq->fetch_assoc()) $mix[$row['k']] = (int)$row['n'];

$mixColors = [
    'sessions_completed' => '#1B6FD1',
    'avg_rating'         => '#B7791F',
    'community'          => '#0E6C77',
    'top_mentor'         => '#5A3E96',
    'manual'             => '#9CA3AF',
];
$mixLabels = [
    'sessions_completed' => 'Sessions',
    'avg_rating'         => 'Rating',
    'community'          => 'Community',
    'top_mentor'         => 'Top mentor',
    'manual'             => 'Manual',
];

/* ── Recent awards ────────────────────────────────────────────────────── */
$recent = $con->query("
    SELECT ub.user_badge_id, ub.awarded_at, ub.awarded_by,
           b.name AS badge_name, b.icon, b.color,
           u.user_id, CONCAT_WS(' ', u.firstname, u.lastname) AS person,
           pr.profile_image
      FROM user_badges ub
      JOIN badges b ON b.badge_id = ub.badge_id
      JOIN users  u ON u.user_id  = ub.user_id
      LEFT JOIN profile pr ON pr.user_id = u.user_id
     ORDER BY ub.awarded_at DESC
     LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

/* Everyone a badge can be given to. Badges are mentor recognition, so this is
   the same population action_check_badges.php walks. */
$mentors = $con->query("
    SELECT user_id, CONCAT_WS(' ', firstname, lastname) AS name
      FROM users WHERE role = 'mentor' AND status = 'active'
     ORDER BY firstname, lastname
")->fetch_all(MYSQLI_ASSOC);

$allBadges = $con->query("SELECT badge_id, name, is_active FROM badges ORDER BY name")->fetch_all(MYSQLI_ASSOC);

/** Rebuild this page's URL with one thing changed. */
function bg_url(array $over = []): string
{
    $qs = array_merge($_GET, $over);
    unset($qs['msg']);
    $qs = array_filter($qs, fn($v) => $v !== '' && $v !== null);
    return url('admin-badges') . ($qs ? '?' . http_build_query($qs) : '');
}

include 'layout.php';
require_once __DIR__ . '/includes/sessions_ui.php';
?>

<style>
    .aw-crumb { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--gray-400); margin-bottom: 6px; }
    .aw-crumb b { color: var(--forest); font-weight: 600; }

    .aw-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 14px; align-items: start; }
    .aw-stack { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
    .aw-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }

    /* Tabs + filter bar */
    .aw-tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--gray-200); margin-bottom: 14px; flex-wrap: wrap; }
    .aw-tab { padding: 10px 15px; font-size: 13px; font-weight: 600; color: var(--gray-500);
              text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap; }
    .aw-tab:hover { color: var(--forest); }
    .aw-tab.on { color: var(--mint); border-bottom-color: var(--mint); }
    .aw-tab span { color: var(--gray-400); font-weight: 500; }

    .aw-bar { display: flex; gap: 9px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
    .aw-search { position: relative; flex: 1; min-width: 200px; }
    .aw-search svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--gray-400); }
    .aw-search input { width: 100%; padding: 10px 12px 10px 34px; border: 1px solid var(--gray-200); border-radius: 10px;
                       font-family: inherit; font-size: 13px; color: var(--gray-800); outline: none; background: #fff; }
    .aw-search input:focus { border-color: var(--mint); box-shadow: 0 0 0 3px rgba(0,135,207,.13); }
    .aw-bar select { padding: 10px 12px; border: 1px solid var(--gray-200); border-radius: 10px; background: #fff;
                     font-family: inherit; font-size: 13px; color: var(--gray-700); outline: none; }
    .aw-bar button.go { padding: 10px 16px; border: 1px solid var(--forest); border-radius: 10px; background: var(--forest);
                        color: #fff; font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }
    .aw-reset { display: inline-flex; align-items: center; gap: 7px; padding: 10px 15px; border: 1px solid var(--gray-200);
                border-radius: 10px; background: #fff; font-size: 13px; font-weight: 600; color: var(--gray-600); text-decoration: none; }
    .aw-reset:hover { border-color: var(--mint); color: var(--mint); }

    /* Badge cards */
    .bd-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(215px, 1fr)); gap: 14px; }
    .bd-card { background: #fff; border: 1px solid var(--gray-100); border-radius: 14px; padding: 18px 16px 14px;
               box-shadow: 0 1px 2px rgba(16,24,40,.04); display: flex; flex-direction: column; text-align: center; position: relative; }
    .bd-card.off { opacity: .72; }
    .bd-ico { width: 62px; height: 62px; margin: 0 auto 11px; border-radius: 18px; display: grid; place-items: center; }
    .bd-ico svg { width: 30px; height: 30px; }
    .bd-card h3 { margin: 0 0 5px; font-size: 14px; font-weight: 700; color: var(--forest); }
    .bd-card p { margin: 0 0 11px; font-size: 12px; color: var(--gray-500); line-height: 1.55; flex: 1; }
    .bd-chip { display: inline-block; padding: 3px 11px; border-radius: 999px; font-size: 11px; font-weight: 700; margin-bottom: 10px; }
    .bd-crit { font-size: 11.5px; color: var(--gray-400); margin-bottom: 12px; line-height: 1.5; }
    .bd-hold { display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-size: 12.5px;
               color: var(--gray-600); font-weight: 600; margin-bottom: 12px; }
    .bd-hold svg { width: 14px; height: 14px; color: var(--gray-400); }
    .bd-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px;
               border-top: 1px solid var(--gray-100); padding-top: 11px; }

    /* Switch */
    .bd-sw { display: inline-flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 600; color: var(--gray-500); }
    .bd-sw input { position: absolute; opacity: 0; pointer-events: none; }
    .bd-sw i { width: 36px; height: 20px; border-radius: 999px; background: var(--gray-200); position: relative; transition: background .16s; display: block; }
    .bd-sw i::after { content: ''; position: absolute; top: 3px; left: 3px; width: 14px; height: 14px; border-radius: 50%;
                      background: #fff; transition: transform .16s; box-shadow: 0 1px 2px rgba(0,0,0,.25); }
    .bd-sw input:checked + i { background: #17654B; }
    .bd-sw input:checked + i::after { transform: translateX(16px); }
    .bd-sw input:focus-visible + i { outline: 2px solid var(--mint); outline-offset: 2px; }
    .bd-sw button { all: unset; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }

    /* Row menu */
    .bd-menu { position: relative; }
    .bd-menu > button { width: 30px; height: 30px; border: 1px solid var(--gray-200); border-radius: 9px; background: #fff;
                        color: var(--gray-500); cursor: pointer; display: grid; place-items: center; padding: 0; }
    .bd-menu > button:hover { border-color: var(--mint); color: var(--mint); }
    .bd-menu > button svg { width: 15px; height: 15px; }
    .bd-pop { position: absolute; right: 0; bottom: calc(100% + 6px); min-width: 168px; background: #fff;
              border: 1px solid var(--gray-200); border-radius: 11px; box-shadow: 0 12px 28px -12px rgba(16,24,40,.34);
              padding: 5px; z-index: 40; text-align: left; }
    .bd-pop button, .bd-pop a { display: flex; align-items: center; gap: 9px; width: 100%; padding: 8px 10px; border: 0;
                                border-radius: 8px; background: none; font-family: inherit; font-size: 12.5px; font-weight: 600;
                                color: var(--gray-700); cursor: pointer; text-align: left; text-decoration: none; }
    .bd-pop button:hover, .bd-pop a:hover { background: var(--gray-50, #F7F8FA); color: var(--mint); }
    .bd-pop button.bad:hover { background: #FDECEA; color: #A6301F; }
    .bd-pop svg { width: 14px; height: 14px; flex: none; }
    .bd-pop hr { border: 0; border-top: 1px solid var(--gray-100); margin: 4px 2px; }

    /* Pager */
    .aw-pager { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
    .aw-pager-n { font-size: 12.5px; color: var(--gray-400); }
    .aw-pages { display: flex; gap: 5px; align-items: center; }
    .aw-pages a, .aw-pages span { min-width: 32px; height: 32px; padding: 0 9px; border: 1px solid var(--gray-200); border-radius: 9px;
                                  background: #fff; display: inline-flex; align-items: center; justify-content: center;
                                  font-size: 12.5px; font-weight: 600; color: var(--gray-600); text-decoration: none; }
    .aw-pages a:hover { border-color: var(--mint); color: var(--mint); }
    .aw-pages .on { background: var(--forest); border-color: var(--forest); color: #fff; }
    .aw-pages .dead { opacity: .4; }

    /* Side */
    .aw-donut { display: flex; align-items: center; gap: 16px; }
    .aw-keys { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 7px; }
    .aw-key { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--gray-600); }
    .aw-key i { width: 9px; height: 9px; border-radius: 3px; flex: none; }
    .aw-key b { margin-left: auto; font-weight: 600; color: var(--gray-800); font-variant-numeric: tabular-nums; }
    .aw-mid { position: absolute; inset: 0; display: grid; place-items: center; text-align: center; }
    .aw-mid b { display: block; font-size: 19px; font-weight: 700; color: var(--forest); line-height: 1.1; }
    .aw-mid span { font-size: 9.5px; color: var(--gray-400); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }

    .aw-feed { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-top: 1px solid var(--gray-100); }
    .aw-feed:first-of-type { border-top: 0; padding-top: 0; }
    .aw-av { width: 32px; height: 32px; flex: none; border-radius: 50%; overflow: hidden; background: var(--forest);
             color: #fff; display: grid; place-items: center; font-size: 12px; font-weight: 700; }
    .aw-av img { width: 100%; height: 100%; object-fit: cover; }
    .aw-feed-t { flex: 1; min-width: 0; }
    .aw-feed-t b { display: block; font-size: 12.5px; font-weight: 600; color: var(--gray-800); }
    .aw-feed-t span { display: flex; align-items: center; gap: 5px; font-size: 11.5px; color: var(--gray-400); }
    .aw-feed-t span svg { width: 12px; height: 12px; flex: none; }
    .aw-feed-w { flex: none; font-size: 11px; color: var(--gray-400); white-space: nowrap; }

    .aw-acts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; }
    .aw-acts button, .aw-acts a { display: inline-flex; align-items: center; gap: 8px; padding: 11px 12px; border: 1px solid var(--gray-200);
                                  border-radius: 10px; background: #fff; font-family: inherit; font-size: 12.5px; font-weight: 600;
                                  color: var(--gray-700); cursor: pointer; text-decoration: none; }
    .aw-acts button:hover, .aw-acts a:hover { border-color: var(--mint); color: var(--mint); }
    .aw-acts svg { width: 15px; height: 15px; flex: none; }

    .aw-note { padding: 12px 14px; border-radius: 11px; background: #EAF1FB; color: #1A5C9A; font-size: 12.5px; line-height: 1.6; }
    .aw-note b { display: block; margin-bottom: 2px; }
    .aw-empty { padding: 40px 20px; text-align: center; color: var(--gray-400); font-size: 13px; }

    /* Dialogs */
    .aw-ov { position: fixed; inset: 0; background: rgba(9,14,38,.5); display: none; place-items: center; z-index: 90; padding: 20px; }
    .aw-ov.open { display: grid; }
    .aw-modal { background: #fff; border-radius: 16px; width: min(520px, 100%); max-height: 88vh; overflow-y: auto;
                box-shadow: 0 24px 60px -20px rgba(16,24,40,.5); }
    .aw-modal-hd { padding: 18px 22px 14px; border-bottom: 1px solid var(--gray-100); }
    .aw-modal-hd h3 { margin: 0; font-size: 16px; font-weight: 700; color: var(--forest); }
    .aw-modal-hd p { margin: 3px 0 0; font-size: 12.5px; color: var(--gray-400); }
    .aw-modal-bd { padding: 18px 22px; }
    .aw-modal-ft { padding: 14px 22px 18px; display: flex; justify-content: flex-end; gap: 9px; }
    .aw-f { margin-bottom: 14px; }
    .aw-f label { display: block; font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 5px; }
    .aw-f input[type=text], .aw-f input[type=number], .aw-f select, .aw-f textarea {
        width: 100%; padding: 10px 12px; border: 1px solid var(--gray-200); border-radius: 10px;
        font-family: inherit; font-size: 13.5px; color: var(--gray-800); background: #fff; outline: none; resize: vertical; box-sizing: border-box;
    }
    .aw-f input:focus, .aw-f select:focus, .aw-f textarea:focus { border-color: var(--mint); box-shadow: 0 0 0 3px rgba(0,135,207,.13); }
    .aw-f small { display: block; margin-top: 4px; font-size: 11.5px; color: var(--gray-400); line-height: 1.5; }
    .aw-f-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }

    .aw-pick { display: grid; grid-template-columns: repeat(6, 1fr); gap: 7px; }
    .aw-pick input { position: absolute; opacity: 0; pointer-events: none; }
    .aw-pick label { display: grid; place-items: center; aspect-ratio: 1; border: 1px solid var(--gray-200);
                     border-radius: 10px; cursor: pointer; margin: 0; }
    .aw-pick label svg { width: 19px; height: 19px; }
    .aw-pick input:checked + label { border-color: var(--forest); box-shadow: 0 0 0 2px var(--forest); }
    .aw-pick.tones label { aspect-ratio: 1.4; }

    .aw-btn { padding: 10px 18px; border-radius: 10px; border: 1px solid var(--gray-200); background: #fff;
              font-family: inherit; font-size: 13.5px; font-weight: 600; color: var(--gray-700); cursor: pointer; }
    .aw-btn.primary { background: var(--forest); border-color: var(--forest); color: #fff; }
    .aw-btn.danger { background: #A6301F; border-color: #A6301F; color: #fff; }

    @media (max-width: 1240px) { .aw-grid { grid-template-columns: minmax(0, 1fr); } }
    @media (max-width: 1180px) { .aw-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 620px)  { .aw-stats { grid-template-columns: minmax(0, 1fr); } .aw-f-row { grid-template-columns: minmax(0, 1fr); } }
</style>

<div class="aw-crumb">Content<span>›</span><b>Badges &amp; Achievements</b></div>

<div class="ss-hd">
    <div>
        <h1>Badges &amp; Achievements</h1>
        <p>Recognition mentors earn. Three of these are awarded automatically; the rest you hand out.</p>
    </div>
    <div class="ss-hd-actions">
        <form method="post" action="<?= url('admin-check-badges') ?>" style="margin:0;"
              data-pc-tone="primary" data-pc-ok="Recalculate"
              data-pc-confirm="Recalculate every mentor score and award any badge that has been earned?&#10;Mentors who earn one are notified by email.">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="aw-btn">Run award check</button>
        </form>
        <button type="button" class="ss-export" style="border:0;cursor:pointer;font-family:inherit;" onclick="bdNew()">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
            Create badge
        </button>
    </div>
</div>

<!-- ══════════ Figures ══════════ -->
<div class="aw-stats">
    <?php foreach ([
        ['Badges defined', number_format($totalBadges), $activeBadges . ' active · ' . ($totalBadges - $activeBadges) . ' switched off', '#FEF3D6', '#8A6400', 'star'],
        ['Mentors holding one', number_format($holders), $mentorTotal > 0 ? round($holders / $mentorTotal * 100) . '% of ' . $mentorTotal . ' active mentors' : 'No active mentors', '#E1F3EA', '#17654B', 'one'],
        ['Badges earned', number_format($earned), $autoEarned . ' automatic · ' . ($earned - $autoEarned) . ' by hand', '#E4EEFB', '#1A5C9A', 'check'],
        ['Never earned yet', number_format($neverEarned), 'Badges nobody holds', '#EDEFF3', '#414A5C', 'clock'],
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

<div class="aw-grid">
    <div class="aw-stack">
        <div class="ss-card">
            <!-- Tabs -->
            <div class="aw-tabs">
                <?php foreach ([
                    'all'      => ['All badges', $totalBadges],
                    'active'   => ['Active', $activeBadges],
                    'inactive' => ['Switched off', $totalBadges - $activeBadges],
                ] as $k => [$label, $n]): ?>
                    <a class="aw-tab <?= $tab === $k ? 'on' : '' ?>" href="<?= bg_url(['tab' => $k === 'all' ? null : $k, 'p' => null]) ?>">
                        <?= $label ?> <span><?= $n ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Filters -->
            <form class="aw-bar" method="get" action="<?= url('admin-badges') ?>">
                <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>"><?php endif; ?>
                <input type="hidden" name="per" value="<?= $perPage ?>">
                <span class="aw-search">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="m16.5 16.5 4 4" /></svg>
                    <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search badges by name or description…">
                </span>
                <select name="crit">
                    <option value="">How it's earned — any</option>
                    <?php foreach (aw_criteria_types() as $k => $label): ?>
                        <option value="<?= $k ?>" <?= $critFil === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="go">Apply</button>
                <?php if ($q !== '' || $critFil !== '' || $tab !== 'all'): ?>
                    <a class="aw-reset" href="<?= url('admin-badges') ?>">Reset</a>
                <?php endif; ?>
            </form>

            <!-- Cards -->
            <?php if (!$badges): ?>
                <p class="aw-empty">
                    <?= $total === 0 && $q === '' && $critFil === '' && $tab === 'all'
                        ? 'No badges yet. Create one to start recognising mentors.'
                        : 'No badge matches these filters.' ?>
                </p>
            <?php else: ?>
                <div class="bd-grid">
                    <?php foreach ($badges as $b):
                        [$tint, $ink] = aw_color($b['color']);
                        [$critText, $isAuto, $critShort] = aw_criteria($b);
                        $id = (int)$b['badge_id'];
                    ?>
                        <div class="bd-card <?= (int)$b['is_active'] ? '' : 'off' ?>">
                            <span class="bd-ico" style="background:<?= $tint ?>;color:<?= $ink ?>;"><?= aw_icon($b['icon']) ?></span>
                            <h3><?= htmlspecialchars($b['name']) ?></h3>
                            <p><?= htmlspecialchars((string)$b['description']) ?></p>
                            <div><span class="bd-chip" style="background:<?= $tint ?>;color:<?= $ink ?>;"><?= $critShort ?></span></div>
                            <div class="bd-crit"><?= htmlspecialchars($critText) ?><?= $isAuto ? '' : ' · not automatic' ?></div>
                            <div class="bd-hold">
                                <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="9" cy="8.5" r="3" /><path stroke-linecap="round" d="M3.5 20a5.5 5.5 0 0 1 11 0M16 5.6a3 3 0 0 1 0 5.8M17.5 14.4A5.5 5.5 0 0 1 20.5 20" /></svg>
                                <?= (int)$b['holders'] ?> mentor<?= (int)$b['holders'] === 1 ? '' : 's' ?>
                            </div>
                            <div class="bd-foot">
                                <form method="post" action="<?= url('admin-action-badge') ?>" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="do" value="toggle">
                                    <input type="hidden" name="badge_id" value="<?= $id ?>">
                                    <input type="hidden" name="back" value="<?= htmlspecialchars(bg_url()) ?>">
                                    <button type="submit" class="bd-sw" title="<?= (int)$b['is_active'] ? 'Switch this badge off' : 'Switch this badge on' ?>">
                                        <input type="checkbox" <?= (int)$b['is_active'] ? 'checked' : '' ?> tabindex="-1" aria-hidden="true"><i></i>
                                        <?= (int)$b['is_active'] ? 'Active' : 'Off' ?>
                                    </button>
                                </form>
                                <span class="bd-menu">
                                    <button type="button" onclick="bdMenu(this)" aria-label="More actions for <?= htmlspecialchars($b['name']) ?>">
                                        <svg fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.7" /><circle cx="12" cy="12" r="1.7" /><circle cx="12" cy="19" r="1.7" /></svg>
                                    </button>
                                    <span class="bd-pop" hidden>
                                        <button type="button" onclick='bdEdit(<?= json_encode([
                                            "id" => $id, "name" => $b["name"], "description" => (string)$b["description"],
                                            "criteria_type" => $b["criteria_type"], "criteria_value" => (int)$b["criteria_value"],
                                            "icon" => $b["icon"], "color" => $b["color"],
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20h4L19 9l-4-4L4 16v4Z" /></svg>
                                            Edit badge
                                        </button>
                                        <button type="button" onclick="bdAward(<?= $id ?>)">
                                            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="12" cy="9" r="5.5" /><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 13.5-1 7 4.5-2.4 4.5 2.4-1-7" /></svg>
                                            Award to a mentor
                                        </button>
                                        <a href="<?= url('admin-badge-holders') ?>?id=<?= $id ?>">
                                            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="9" cy="8.5" r="3" /><path stroke-linecap="round" d="M3.5 20a5.5 5.5 0 0 1 11 0M16 5.6a3 3 0 0 1 0 5.8M17.5 14.4A5.5 5.5 0 0 1 20.5 20" /></svg>
                                            Who holds it
                                        </a>
                                        <hr>
                                        <?php /* Single-quoted, with JSON_HEX_QUOT — json_encode() wraps a string
                                                 in double quotes of its own, which ends a double-quoted attribute
                                                 at the first one and leaves the handler unparseable. */ ?>
                                        <button type="button" class="bad" onclick='bdDelete(<?= $id ?>, <?= json_encode($b['name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= (int)$b['holders'] ?>)'>
                                            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 7h14M10 7V5h4v2m-7 0 1 13h8l1-13" /></svg>
                                            Delete badge
                                        </button>
                                    </span>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pager -->
                <div class="aw-pager">
                    <span class="aw-pager-n">
                        Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $total) ?> of <?= $total ?> badge<?= $total === 1 ? '' : 's' ?>
                    </span>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <?php if ($pages > 1): ?>
                            <span class="aw-pages">
                                <?php if ($page > 1): ?><a href="<?= bg_url(['p' => $page - 1]) ?>" aria-label="Previous page">‹</a>
                                <?php else: ?><span class="dead">‹</span><?php endif; ?>
                                <?php for ($i = 1; $i <= $pages; $i++): ?>
                                    <?php if ($i === $page): ?><span class="on"><?= $i ?></span>
                                    <?php else: ?><a href="<?= bg_url(['p' => $i]) ?>"><?= $i ?></a><?php endif; ?>
                                <?php endfor; ?>
                                <?php if ($page < $pages): ?><a href="<?= bg_url(['p' => $page + 1]) ?>" aria-label="Next page">›</a>
                                <?php else: ?><span class="dead">›</span><?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <form method="get" action="<?= url('admin-badges') ?>" style="margin:0;">
                            <?php foreach (['tab' => $tab === 'all' ? '' : $tab, 'q' => $q, 'crit' => $critFil] as $k => $v): ?>
                                <?php if ($v !== '') : ?><input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>"><?php endif; ?>
                            <?php endforeach; ?>
                            <select name="per" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid var(--gray-200);border-radius:9px;font-family:inherit;font-size:12.5px;color:var(--gray-600);background:#fff;">
                                <?php foreach ([10, 20, 50] as $n): ?>
                                    <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?> per page</option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══════════ Side ══════════ -->
    <div class="aw-stack">
        <div class="ss-card">
            <h2>How badges are earned</h2>
            <p style="margin:-9px 0 14px;font-size:12px;color:var(--gray-400);line-height:1.5;">Every badge defined, grouped by the rule that awards it.</p>
            <?php $mixTotal = array_sum($mix); $R = 34; $SW = 15; $C = 2 * M_PI * $R; $off = 0; ?>
            <div class="aw-donut">
                <div style="position:relative;flex:none;width:92px;height:92px;">
                    <svg width="92" height="92" viewBox="0 0 92 92" role="img" aria-label="Badges by how they are earned">
                        <circle cx="46" cy="46" r="<?= $R ?>" fill="none" stroke="#EDEDED" stroke-width="<?= $SW ?>" />
                        <?php foreach ($mix as $k => $n):
                            if ($n === 0) continue;
                            $len = $C * $n / max(1, $mixTotal); ?>
                            <circle cx="46" cy="46" r="<?= $R ?>" fill="none" stroke="<?= $mixColors[$k] ?? '#9CA3AF' ?>" stroke-width="<?= $SW ?>"
                                    stroke-dasharray="<?= round($len, 2) ?> <?= round($C - $len, 2) ?>"
                                    stroke-dashoffset="<?= round(-$off, 2) ?>" transform="rotate(-90 46 46)" />
                            <?php $off += $len; ?>
                        <?php endforeach; ?>
                    </svg>
                    <div class="aw-mid"><div><b><?= $mixTotal ?></b><span>badges</span></div></div>
                </div>
                <div class="aw-keys">
                    <?php foreach ($mix as $k => $n): ?>
                        <span class="aw-key">
                            <i style="background:<?= $mixColors[$k] ?? '#9CA3AF' ?>"></i><?= $mixLabels[$k] ?? $k ?>
                            <b><?= $n ?></b>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="ss-card">
            <h2>Recent awards<?php if ($earned > 6): ?><a href="<?= url('admin-badge-holders') ?>">View all</a><?php endif; ?></h2>
            <?php if (!$recent): ?>
                <p class="aw-empty" style="padding:20px 0;">Nothing has been awarded yet.</p>
            <?php else: foreach ($recent as $r):
                [$tint, $ink] = aw_color($r['color']); ?>
                <div class="aw-feed">
                    <span class="aw-av">
                        <?php if (!empty($r['profile_image'])): ?><img src="<?= htmlspecialchars($r['profile_image']) ?>" alt="">
                        <?php else: ?><?= htmlspecialchars(strtoupper(substr(trim($r['person']), 0, 1))) ?><?php endif; ?>
                    </span>
                    <span class="aw-feed-t">
                        <b><?= htmlspecialchars($r['person']) ?></b>
                        <span>
                            <span style="color:<?= $ink ?>;display:inline-flex;"><?= aw_icon($r['icon']) ?></span>
                            <?= htmlspecialchars($r['badge_name']) ?><?= $r['awarded_by'] ? '' : ' · automatic' ?>
                        </span>
                    </span>
                    <span class="aw-feed-w"><?= aw_ago($r['awarded_at']) ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="ss-card">
            <h2>Quick actions</h2>
            <div class="aw-acts">
                <button type="button" onclick="bdNew()">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                    New badge
                </button>
                <button type="button" onclick="bdAward(0)">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="12" cy="9" r="5.5" /><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 13.5-1 7 4.5-2.4 4.5 2.4-1-7" /></svg>
                    Award a badge
                </button>
                <a href="<?= url('admin-badge-holders') ?>">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7.5V12l2.8 1.8" /></svg>
                    Award history
                </a>
                <a href="<?= url('admin-certificates') ?>">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 3.5H7.5A1.5 1.5 0 0 0 6 5v14a1.5 1.5 0 0 0 1.5 1.5h9A1.5 1.5 0 0 0 18 19V7.5L14 3.5Zm0 0V8h4" /></svg>
                    Certificates
                </a>
            </div>
        </div>

        <div class="ss-card">
            <h2>What "automatic" means</h2>
            <div class="aw-note">
                <b>Only two rules award themselves.</b>
                Completed sessions and average rating are checked whenever you run the award check above, and
                every mentor who qualifies is given the badge and emailed. Community, top mentor and manual
                badges are never awarded on their own — you hand those out from the ⋮ menu on a card.
            </div>
        </div>
    </div>
</div>

<!-- ══════════ Create / edit badge ══════════ -->
<div class="aw-ov" id="bdForm" role="dialog" aria-modal="true" aria-labelledby="bdFormTitle">
    <div class="aw-modal">
        <form method="post" action="<?= url('admin-action-badge') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="do" id="bdDo" value="create">
            <input type="hidden" name="badge_id" id="bdId" value="0">
            <input type="hidden" name="back" value="<?= htmlspecialchars(bg_url()) ?>">

            <div class="aw-modal-hd">
                <h3 id="bdFormTitle">Create badge</h3>
                <p id="bdFormSub">Mentors see this on their profile once they earn it.</p>
            </div>
            <div class="aw-modal-bd">
                <div class="aw-f">
                    <label for="bdName">Name</label>
                    <input type="text" id="bdName" name="name" maxlength="100" required placeholder="e.g. Consistency Pro">
                </div>
                <div class="aw-f">
                    <label for="bdDesc">Description</label>
                    <textarea id="bdDesc" name="description" rows="2" maxlength="400" placeholder="What a mentor did to earn it"></textarea>
                </div>
                <div class="aw-f-row">
                    <div class="aw-f">
                        <label for="bdCrit">How it is earned</label>
                        <select id="bdCrit" name="criteria_type" onchange="bdCritChange()">
                            <?php foreach (aw_criteria_types() as $k => $label): ?>
                                <option value="<?= $k ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="aw-f" id="bdValWrap">
                        <label for="bdVal">Threshold</label>
                        <input type="number" id="bdVal" name="criteria_value" min="0" max="10000" value="0">
                        <small id="bdValHelp">Sessions a mentor must have completed.</small>
                    </div>
                </div>
                <div class="aw-f">
                    <label>Icon</label>
                    <div class="aw-pick">
                        <?php foreach (aw_badge_icons() as $k => $label): ?>
                            <input type="radio" name="icon" id="ic_<?= $k ?>" value="<?= $k ?>" <?= $k === 'medal' ? 'checked' : '' ?>>
                            <label for="ic_<?= $k ?>" title="<?= $label ?>"><?= aw_icon($k) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="aw-f" style="margin-bottom:0;">
                    <label>Colour</label>
                    <div class="aw-pick tones">
                        <?php foreach (aw_colors() as $k => [$tint, $ink]): ?>
                            <input type="radio" name="color" id="co_<?= $k ?>" value="<?= $k ?>" <?= $k === 'amber' ? 'checked' : '' ?>>
                            <label for="co_<?= $k ?>" style="background:<?= $tint ?>;" title="<?= ucfirst($k) ?>">
                                <span style="width:14px;height:14px;border-radius:50%;background:<?= $ink ?>;display:block;"></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="aw-modal-ft">
                <button type="button" class="aw-btn" onclick="awClose('bdForm')">Cancel</button>
                <button type="submit" class="aw-btn primary" id="bdSubmit">Create badge</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════ Award a badge ══════════ -->
<div class="aw-ov" id="bdAwardM" role="dialog" aria-modal="true" aria-labelledby="bdAwardTitle">
    <div class="aw-modal">
        <form method="post" action="<?= url('admin-award-badge') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <div class="aw-modal-hd">
                <h3 id="bdAwardTitle">Award a badge</h3>
                <p>The mentor is notified, and by email if notifications are on.</p>
            </div>
            <div class="aw-modal-bd">
                <?php if (!$mentors): ?>
                    <p style="margin:0;font-size:13px;color:var(--gray-500);">There are no active mentors to award a badge to.</p>
                <?php else: ?>
                    <div class="aw-f">
                        <label for="awMentor">Mentor</label>
                        <select id="awMentor" name="user_id" required>
                            <option value="">Choose a mentor…</option>
                            <?php foreach ($mentors as $m): ?>
                                <option value="<?= (int)$m['user_id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="aw-f" style="margin-bottom:0;">
                        <label for="awBadge">Badge</label>
                        <select id="awBadge" name="badge_id" required>
                            <option value="">Choose a badge…</option>
                            <?php foreach ($allBadges as $b): ?>
                                <option value="<?= (int)$b['badge_id'] ?>"><?= htmlspecialchars($b['name']) ?><?= (int)$b['is_active'] ? '' : ' (switched off)' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small>A mentor who already holds this badge is left as they are — it is not awarded twice.</small>
                    </div>
                <?php endif; ?>
            </div>
            <div class="aw-modal-ft">
                <button type="button" class="aw-btn" onclick="awClose('bdAwardM')">Cancel</button>
                <?php if ($mentors): ?><button type="submit" class="aw-btn primary">Award badge</button><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ══════════ Delete ══════════ -->
<div class="aw-ov" id="bdDel" role="dialog" aria-modal="true" aria-labelledby="bdDelTitle">
    <div class="aw-modal" style="width:min(440px,100%);">
        <form method="post" action="<?= url('admin-action-badge') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="badge_id" id="bdDelId" value="0">
            <input type="hidden" name="back" value="<?= htmlspecialchars(bg_url()) ?>">
            <div class="aw-modal-hd">
                <h3 id="bdDelTitle">Delete this badge?</h3>
            </div>
            <div class="aw-modal-bd">
                <p style="margin:0 0 12px;font-size:13.5px;color:var(--gray-700);line-height:1.6;" id="bdDelText"></p>
                <div class="aw-note" style="background:#FEF6DC;color:#7A5A00;">
                    <b>Switching it off is usually what you want.</b>
                    An inactive badge stops being awarded and disappears from profiles, but everyone who earned it
                    keeps the record. Deleting removes the badge and every award of it, and cannot be undone.
                </div>
            </div>
            <div class="aw-modal-ft">
                <button type="button" class="aw-btn" onclick="awClose('bdDel')">Cancel</button>
                <button type="submit" class="aw-btn danger">Delete permanently</button>
            </div>
        </form>
    </div>
</div>

<script>
    function awOpen(id) {
        document.getElementById(id).classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function awClose(id) {
        document.getElementById(id).classList.remove('open');
        document.body.style.overflow = '';
    }

    // A click on the backdrop closes; a click inside the panel does not.
    document.querySelectorAll('.aw-ov').forEach(function (ov) {
        ov.addEventListener('click', function (e) {
            if (e.target === ov) awClose(ov.id);
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.aw-ov.open').forEach(function (ov) { awClose(ov.id); });
        bdCloseMenus();
    });

    /* ── Card menus ── */
    function bdCloseMenus(except) {
        document.querySelectorAll('.bd-pop').forEach(function (p) {
            if (p !== except) p.hidden = true;
        });
    }

    function bdMenu(btn) {
        var pop = btn.parentNode.querySelector('.bd-pop');
        var wasOpen = !pop.hidden;
        bdCloseMenus(pop);
        pop.hidden = wasOpen;
    }
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.bd-menu')) bdCloseMenus();
    });

    /* ── Create / edit ── */
    var BD_AUTO = { sessions_completed: 1, avg_rating: 1 };

    function bdCritChange() {
        var crit = document.getElementById('bdCrit').value;
        var wrap = document.getElementById('bdValWrap');
        var help = document.getElementById('bdValHelp');
        var needsValue = !!BD_AUTO[crit];
        wrap.style.display = needsValue ? '' : 'none';
        if (crit === 'avg_rating') {
            help.textContent = 'Stored as the rating times ten — 48 means 4.8 stars. Five completed sessions are also required.';
        } else {
            help.textContent = 'Sessions a mentor must have completed.';
        }
    }

    function bdNew() {
        var f = document.getElementById('bdForm');
        f.querySelector('form').reset();
        document.getElementById('bdDo').value = 'create';
        document.getElementById('bdId').value = '0';
        document.getElementById('bdFormTitle').textContent = 'Create badge';
        document.getElementById('bdFormSub').textContent = 'Mentors see this on their profile once they earn it.';
        document.getElementById('bdSubmit').textContent = 'Create badge';
        bdCritChange();
        awOpen('bdForm');
        document.getElementById('bdName').focus();
    }

    function bdEdit(b) {
        bdCloseMenus();
        document.getElementById('bdDo').value = 'update';
        document.getElementById('bdId').value = b.id;
        document.getElementById('bdName').value = b.name;
        document.getElementById('bdDesc').value = b.description;
        document.getElementById('bdCrit').value = b.criteria_type;
        document.getElementById('bdVal').value = b.criteria_value;
        var ic = document.getElementById('ic_' + b.icon);
        if (ic) ic.checked = true;
        var co = document.getElementById('co_' + b.color);
        if (co) co.checked = true;
        document.getElementById('bdFormTitle').textContent = 'Edit badge';
        document.getElementById('bdFormSub').textContent = 'Changes show wherever this badge already appears.';
        document.getElementById('bdSubmit').textContent = 'Save changes';
        bdCritChange();
        awOpen('bdForm');
    }

    function bdAward(badgeId) {
        bdCloseMenus();
        var sel = document.getElementById('awBadge');
        if (sel) sel.value = badgeId ? String(badgeId) : '';
        awOpen('bdAwardM');
    }

    function bdDelete(id, name, holders) {
        bdCloseMenus();
        document.getElementById('bdDelId').value = id;
        document.getElementById('bdDelText').textContent =
            holders > 0
                ? '"' + name + '" is held by ' + holders + ' mentor' + (holders === 1 ? '' : 's') + '. Deleting it takes the badge off their profiles too.'
                : '"' + name + '" has not been awarded to anyone.';
        awOpen('bdDel');
    }

    bdCritChange();
</script>
