<?php

/**
 * admin/reports.php — Reports & Analytics → Overall summary.
 *
 * One page that answers "how is the platform doing" from the tables this app
 * actually writes: who joined, who did something, how many sessions ran, how
 * many assessments were submitted, how much people talked to each other.
 *
 * Every figure on this page is a count of rows, measured over a date range you
 * choose, compared against the window of the same length immediately before
 * it. Nothing is modelled, projected or rounded up into a nicer number.
 *
 * Deliberately absent: average time on site, pages per visit, uptime and
 * response time. This install records no page views and runs no monitor, so
 * those four can only be invented. The "What this page cannot tell you" card
 * says so on the page rather than filling the space with a plausible figure.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/report_data.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$R    = rp_range($con, $_GET);
$from = $R['from'];
$to   = $R['to'];

$now  = rp_window($con, $from, $to);
$prev = $R['prev'] ? rp_window($con, $R['prev'][0], $R['prev'][1]) : null;
$d    = fn(string $k) => rp_delta($now[$k], $prev ? $prev[$k] : null);

/* Query string carried onto the export and the preset links. */
$qs = ['range' => $R['key']];
if ($R['key'] === 'custom') { $qs['from'] = $from; $qs['to'] = $to; }
$exportQs = http_build_query($qs);

/* ── Platform totals, all time — the range does not apply to these ────── */
$totalMembers = PlatformStatsRepository::accountFigures($con)['members'];
$roleMix      = SummaryRepository::roleMix($con);

/* ── Member growth: cumulative, by role, across the range ─────────────── */
$B = rp_buckets($from, $to);

$growth = [];
foreach (array_keys($B['keys']) as $k) $growth[$k] = ['mentee' => 0, 'mentor' => 0];
foreach (SummaryRepository::joinsPerBucket($con, $B['unit'], $from, $to) as $row) {
    if (isset($growth[$row['k']])) $growth[$row['k']][$row['role']] = (int)$row['n'];
}

// Everyone who was already registered before the range starts, so the line
// begins where the platform actually stood rather than at zero.
$base = SummaryRepository::membersBefore($con, $from);

$cum = [];
$runM = $base['mentee'];
$runT = $base['mentor'];
foreach ($growth as $k => $v) {
    $runM += $v['mentee'];
    $runT += $v['mentor'];
    $cum[$k] = ['mentee' => $runM, 'mentor' => $runT];
}

/* ── Sessions per bucket ──────────────────────────────────────────────── */
$sessSeries = [];
foreach (array_keys($B['keys']) as $k) $sessSeries[$k] = ['completed' => 0, 'other' => 0];
foreach (SummaryRepository::sessionsPerBucket($con, $B['unit'], $from, $to) as $row) {
    if (isset($sessSeries[$row['k']])) {
        $sessSeries[$row['k']] = ['completed' => (int)$row['done'], 'other' => (int)$row['rest']];
    }
}

/* ── Where the activity is ────────────────────────────────────────────── */
$areas    = rp_areas($now);
$areaTotal = array_sum(array_column($areas, 1));

/* ── Busiest subjects ─────────────────────────────────────────────────── */
$subjects = SummaryRepository::subjects($con, $from, $to, 6);
$subjectPeak = $subjects ? max(array_column($subjects, 'n')) : 1;

/* ── Engagement ───────────────────────────────────────────────────────── */
// Members only, as "Active members" is: an admin signing in is staff at work.
$people   = SummaryRepository::signInPeople($con, $from, $to);
$signedIn = $people['people'];
// Came back on a second day — the only "retention" this data can support.
$returned = $people['returned'];

$bookingMentees = SummaryRepository::bookingMentees($con, $from, $to);

$ratingRow = SummaryRepository::ratings($con, $from, $to);
$avgRating = ((int)$ratingRow['n']) > 0 ? round((float)$ratingRow['a'], 1) : null;

$concluded  = SummaryRepository::concluded($con, $from, $to);
$completion = $concluded > 0 ? round($now['completed'] / $concluded * 100) : null;

/* ── Platform health ──────────────────────────────────────────────────── */
$dbBytes = SummaryRepository::databaseBytes($con);

[$upN, $upB] = rp_files_in([PUBLIC_PATH . '/uploads']);
// Verification documents and report images, kept where the web server will
// not hand them out. They are files the platform holds all the same.
[$privN, $privB] = rp_files_in([VerificationFiles::dir(), ReportService::proofDir()]);

$mail = ['sent' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => 0];
foreach (SummaryRepository::emailStatuses($con, $from, $to) as $status => $n) $mail[$status] = $n;
$mailTotal = array_sum($mail);

// Open reports are pending or urgent, as the bell, User Management and
// Notifications count them, and they are handled on Notifications.
$waitingFor = AdminUserRepository::queueCounts($con);
$queue = [
    ['Verifications waiting', $waitingFor['verifications'], url('admin-users') . '?tab=pending'],
    ['Session requests waiting', PlatformStatsRepository::sessionFigures($con)['pending'], url('admin-sessions') . '?tab=pending'],
    ['Open reports', $waitingFor['reports'], url('admin-notifications') . '?tab=reports'],
];

/* ── Feed ─────────────────────────────────────────────────────────────── */
$feed  = rp_feed($con, $from, $to, 12);
$kinds = rp_feed_kinds();

/* ── Observations ─────────────────────────────────────────────────────────
 * Sentences, but every one of them is a sentence about a number computed
 * above. Each is emitted only when the data behind it is there — no filler
 * when the range is quiet.
 */
$notes = [];
$dm = $d('joined');
if ($dm && $dm[0] !== 'flat' && $dm[0] !== 'new') {
    $notes[] = [$dm[0] === 'up' ? 'up' : 'down',
        $now['joined'] . ' member' . ($now['joined'] === 1 ? '' : 's') . ' joined, '
        . ($dm[0] === 'up' ? 'up' : 'down') . ' ' . abs($dm[1]) . '% on the previous ' . $R['days'] . ' days.'];
}
if ($completion !== null) {
    $notes[] = [$completion >= 70 ? 'up' : ($completion >= 40 ? 'info' : 'down'),
        $completion . '% of the ' . $concluded . ' session' . ($concluded === 1 ? '' : 's')
        . ' that concluded in this range were completed.'];
}
if ($avgRating !== null) {
    $notes[] = [$avgRating >= 4 ? 'up' : 'info',
        'Mentors averaged ' . number_format($avgRating, 1) . ' out of 5 across '
        . $ratingRow['n'] . ' review' . ((int)$ratingRow['n'] === 1 ? '' : 's') . '.'];
}
if ($signedIn > 0) {
    $notes[] = ['info', $returned . ' of the ' . $signedIn . ' member'
        . ($signedIn === 1 ? '' : 's') . ' who signed in came back on a second day.'];
}
if ($mail['failed'] > 0) {
    $notes[] = ['down', $mail['failed'] . ' notification email'
        . ($mail['failed'] === 1 ? '' : 's') . ' failed to send in this range.'];
}
$waiting = array_sum(array_column($queue, 1));
if ($waiting > 0) {
    $notes[] = ['warn', $waiting . ' item' . ($waiting === 1 ? '' : 's') . ' across the platform are waiting on an admin.'];
}
if ($now['resources'] === 0 && $areaTotal > 0) {
    $notes[] = ['info', 'No study resources were uploaded in this range.'];
}

$current_page = 'reports';
include 'layout.php';
require_once __DIR__ . '/includes/sessions_ui.php';
?>

<style>
    .rs-crumb { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--gray-400); margin-bottom: 6px; }
    .rs-crumb a { color: var(--gray-500); text-decoration: none; font-weight: 600; }
    .rs-crumb a:hover { color: var(--mint); }
    .rs-crumb b { color: var(--forest); font-weight: 600; }

    /* ── Range bar ── */
    .rs-range {
        display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
        padding: 11px 13px; background: #fff; border: 1px solid var(--gray-100);
        border-radius: 13px; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(16,24,40,.04);
    }
    .rs-range-k { font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--gray-400); margin-right: 2px; }
    .rs-chip {
        padding: 7px 13px; border: 1px solid var(--gray-200); border-radius: 9px; background: #fff;
        font-size: 12.5px; font-weight: 600; color: var(--gray-600); text-decoration: none; white-space: nowrap;
    }
    .rs-chip:hover { border-color: var(--mint); color: var(--mint); }
    .rs-chip.on { background: var(--forest); border-color: var(--forest); color: #fff; }
    .rs-custom { display: flex; align-items: center; gap: 6px; margin-left: auto; }
    .rs-custom input {
        padding: 6px 9px; border: 1px solid var(--gray-200); border-radius: 8px;
        font-family: inherit; font-size: 12.5px; color: var(--gray-700); outline: none;
    }
    .rs-custom input:focus { border-color: var(--mint); box-shadow: 0 0 0 3px rgba(0,135,207,.13); }
    .rs-custom button {
        padding: 7px 13px; border: 1px solid var(--forest); border-radius: 9px; background: var(--forest);
        color: #fff; font-family: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer;
    }
    .rs-custom span { font-size: 12px; color: var(--gray-400); }

    /* ── Tiles: five, not four ── */
    .rs-stats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }

    /* ── Body ── */
    .rs-grid { display: grid; grid-template-columns: minmax(0, 1fr) 322px; gap: 14px; align-items: start; }
    .rs-stack { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
    /* Cards in a pair stretch to the taller of the two, so their bottom edges
       line up instead of leaving a hole under the shorter one. */
    .rs-cols { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }

    .rs-sub { margin: -9px 0 14px; font-size: 12px; color: var(--gray-400); line-height: 1.5; }

    /* ── Charts ── */
    .rs-chart { width: 100%; height: 168px; display: block; overflow: visible; }
    .rs-legend { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 10px; }
    .rs-legend span { display: inline-flex; align-items: center; gap: 6px; font-size: 11.5px; color: var(--gray-500); font-weight: 600; }
    .rs-legend i { width: 9px; height: 9px; border-radius: 3px; flex: none; }
    .rs-axis { font-size: 9.5px; fill: var(--gray-400); font-family: inherit; }

    /* ── Donut ── */
    .rs-donut { display: flex; align-items: center; gap: 16px; }
    .rs-donut-mid { position: absolute; inset: 0; display: grid; place-items: center; text-align: center; }
    .rs-donut-mid b { display: block; font-size: 19px; font-weight: 700; color: var(--forest); line-height: 1.1; font-variant-numeric: tabular-nums; }
    .rs-donut-mid span { font-size: 9.5px; color: var(--gray-400); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
    .rs-keys { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 7px; }
    .rs-key { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--gray-600); }
    .rs-key i { width: 9px; height: 9px; border-radius: 3px; flex: none; }
    .rs-key b { margin-left: auto; font-weight: 600; color: var(--gray-800); font-variant-numeric: tabular-nums; }
    .rs-key span.p { font-size: 11px; color: var(--gray-400); width: 34px; text-align: right; font-variant-numeric: tabular-nums; }

    /* ── Bars ── */
    .rs-bar { display: flex; align-items: center; gap: 10px; font-size: 12.5px; color: var(--gray-700); padding: 6px 0; }
    .rs-bar > b { width: 108px; flex: none; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rs-bar-t { flex: 1; height: 7px; border-radius: 999px; background: var(--gray-100); overflow: hidden; }
    .rs-bar-t i { display: block; height: 100%; border-radius: 999px; }
    .rs-bar-n { flex: none; width: 30px; text-align: right; font-variant-numeric: tabular-nums; color: var(--gray-500); font-weight: 600; }

    /* ── Key/value rows ── */
    .rs-kv { display: flex; align-items: baseline; gap: 10px; padding: 9px 0; border-top: 1px solid var(--gray-100); font-size: 13px; }
    .rs-kv:first-of-type { border-top: 0; padding-top: 0; }
    .rs-kv span.k { color: var(--gray-500); flex: 1; min-width: 0; }
    .rs-kv b { font-weight: 700; color: var(--forest); font-variant-numeric: tabular-nums; }
    .rs-kv small { display: block; font-size: 11px; color: var(--gray-400); font-weight: 500; }

    /* ── Queue rows ── */
    .rs-q { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-top: 1px solid var(--gray-100);
            font-size: 13px; color: var(--gray-700); text-decoration: none; }
    .rs-q:first-of-type { border-top: 0; padding-top: 0; }
    .rs-q:hover { color: var(--mint); }
    .rs-q b { margin-left: auto; font-variant-numeric: tabular-nums; padding: 2px 10px; border-radius: 999px;
              background: var(--gray-100); color: var(--gray-600); font-size: 12px; }
    .rs-q.hot b { background: #FEF0EC; color: #A6301F; }

    /* ── Feed ── */
    .rs-feed { width: 100%; border-collapse: collapse; font-size: 13px; }
    .rs-feed th { text-align: left; padding: 0 10px 9px 0; font-size: 10.5px; font-weight: 700;
                  text-transform: uppercase; letter-spacing: .05em; color: var(--gray-400); }
    .rs-feed td { padding: 10px 10px 10px 0; border-top: 1px solid var(--gray-100); vertical-align: top; }
    .rs-feed td:last-child, .rs-feed th:last-child { padding-right: 0; text-align: right; }
    .rs-feed .w { font-weight: 600; color: var(--gray-800); }
    .rs-feed .dt { color: var(--gray-400); font-size: 12px; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .rs-feed .de { color: var(--gray-600); }
    .rs-tag { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }

    /* ── Observations ── */
    .rs-note { display: flex; gap: 10px; padding: 10px 0; border-top: 1px solid var(--gray-100); font-size: 12.5px; line-height: 1.55; color: var(--gray-700); }
    .rs-note:first-of-type { border-top: 0; padding-top: 0; }
    .rs-note i { flex: none; width: 20px; height: 20px; border-radius: 6px; display: grid; place-items: center;
                 font-style: normal; font-size: 12px; font-weight: 700; margin-top: 1px; }
    .rs-note.up i    { background: #E6F5EE; color: #17654B; }
    .rs-note.down i  { background: #FBE5E1; color: #A6301F; }
    .rs-note.warn i  { background: #FEF6DC; color: #8A6400; }
    .rs-note.info i  { background: #EAF1FB; color: #1A5C9A; }

    .rs-gap { padding: 12px 14px; border-radius: 11px; background: #EAF1FB; color: #1A5C9A; font-size: 12.5px; line-height: 1.6; }
    /* Direct child only — a bold term inside a list item has to stay inline,
       or every item breaks across three lines. */
    .rs-gap > b { display: block; margin-bottom: 3px; }
    .rs-gap ul { margin: 8px 0 0; padding-left: 18px; }
    .rs-gap li { margin-bottom: 5px; }
    .rs-empty { padding: 22px 0; text-align: center; font-size: 12.5px; color: var(--gray-400); }

    @media (max-width: 1400px) { .rs-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 1240px) { .rs-grid { grid-template-columns: minmax(0, 1fr); } }
    @media (max-width: 900px)  { .rs-cols { grid-template-columns: minmax(0, 1fr); } .rs-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 620px)  { .rs-stats { grid-template-columns: minmax(0, 1fr); } .rs-custom { margin-left: 0; } }
</style>

<div class="rs-crumb">
    <a href="<?= url('admin-reports') ?>">Reports</a><span>›</span><b>Overall summary</b>
</div>

<div class="ss-hd">
    <div>
        <h1>Overall summary</h1>
        <p>Everything PeerConnect recorded <?= $R['key'] === 'all' ? 'since the first member joined' : 'between ' . htmlspecialchars($R['label']) ?>.</p>
    </div>
    <div class="ss-hd-actions">
        <a class="ss-export" href="<?= url('admin-reports-export') . '?' . htmlspecialchars($exportQs) ?>">
            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0 4-4m-4 4-4-4M4 19h16" /></svg>
            Export CSV
        </a>
    </div>
</div>

<!-- ══════════ Range ══════════ -->
<div class="rs-range">
    <span class="rs-range-k">Range</span>
    <?php foreach (rp_presets() as $k => $label): ?>
        <a class="rs-chip <?= $R['key'] === $k ? 'on' : '' ?>" href="<?= url('admin-reports') ?>?range=<?= $k ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <form class="rs-custom" method="get" action="<?= url('admin-reports') ?>">
        <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" max="<?= date('Y-m-d') ?>" aria-label="From">
        <span>to</span>
        <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" max="<?= date('Y-m-d') ?>" aria-label="To">
        <button type="submit">Apply</button>
    </form>
</div>

<!-- ══════════ Headline figures ══════════ -->
<div class="rs-stats">
    <?php
    $tiles = [
        ['New members',  $now['joined'],      $d('joined'),      '#EAF1FB', '#1A5C9A', 'users'],
        ['Active members', $now['active'],    $d('active'),      '#E6F5EE', '#17654B', 'pulse'],
        ['Sessions',     $now['sessions'],    $d('sessions'),    '#EAF6FB', '#0087CF', 'cal'],
        ['Assessments',  $now['assessments'], $d('assessments'), '#F1ECFA', '#5A3E96', 'quiz'],
        ['Messages',     $now['messages'],    $d('messages'),    '#FEF6DC', '#8A6400', 'chat'],
    ];
    foreach ($tiles as [$k, $v, $dl, $bg, $fg, $ico]):
        $cls = $dl && in_array($dl[0], ['up', 'down'], true) ? ' ss-trend ' . $dl[0] : '';
    ?>
        <div class="ss-stat">
            <span class="ss-stat-ico" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= rp_icon($ico) ?></span>
            <div style="min-width:0;">
                <div class="ss-stat-k"><?= $k ?></div>
                <div class="ss-stat-v"><?= number_format($v) ?></div>
                <div class="ss-stat-s<?= $cls ?>"><?= htmlspecialchars(rp_delta_text($dl, 'vs previous ' . $R['days'] . 'd')) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="rs-grid">
    <div class="rs-stack">

        <div class="rs-cols">
            <!-- ── Member growth ── -->
            <div class="ss-card">
                <h2>Member growth</h2>
                <p class="rs-sub">Everyone registered, counted up to the end of each <?= $B['unit'] ?>.</p>
                <?php
                $keys = array_keys($cum);
                $n    = count($keys);
                $peak = 1;
                foreach ($cum as $v) $peak = max($peak, $v['mentee'], $v['mentor']);
                $W = 500; $H = 168; $padL = 30; $padB = 20; $padT = 8;
                $gx = fn($i) => $padL + ($n > 1 ? $i * (($W - $padL - 10) / ($n - 1)) : ($W - $padL) / 2);
                $gy = fn($v) => $H - $padB - ($v / $peak) * ($H - $padB - $padT);
                ?>
                <?php if ($n === 0): ?>
                    <p class="rs-empty">This range has no days in it.</p>
                <?php else: ?>
                    <svg class="rs-chart" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img"
                         aria-label="Registered mentees and mentors over time, ending at <?= $runM ?> mentees and <?= $runT ?> mentors">
                        <?php for ($i = 0; $i <= 2; $i++):
                            $val = (int)round($peak * $i / 2); $y = $gy($val); ?>
                            <line x1="<?= $padL ?>" y1="<?= round($y, 1) ?>" x2="<?= $W - 6 ?>" y2="<?= round($y, 1) ?>" stroke="#EDEDED" stroke-width="1" />
                            <text class="rs-axis" x="<?= $padL - 6 ?>" y="<?= round($y + 3, 1) ?>" text-anchor="end"><?= $val ?></text>
                        <?php endfor; ?>
                        <?php $lastCum = $cum ? $cum[$keys[$n - 1]] : ['mentee' => 0, 'mentor' => 0]; ?>
                        <?php foreach (['mentee' => '#1B6FD1', 'mentor' => '#17654B'] as $role => $col):
                            $pts = [];
                            $i = 0;
                            foreach ($cum as $v) { $pts[] = round($gx($i), 1) . ',' . round($gy($v[$role]), 1); $i++; }
                        ?>
                            <polyline fill="none" stroke="<?= $col ?>" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round"
                                      points="<?= implode(' ', $pts) ?>" />
                            <circle cx="<?= round($gx($n - 1), 1) ?>" cy="<?= round($gy($lastCum[$role]), 1) ?>" r="3.4" fill="<?= $col ?>" />
                        <?php endforeach; ?>
                        <text class="rs-axis" x="<?= $padL ?>" y="<?= $H - 5 ?>"><?= htmlspecialchars(reset($B['keys'])) ?></text>
                        <?php if ($n > 1): ?>
                            <text class="rs-axis" x="<?= $W - 6 ?>" y="<?= $H - 5 ?>" text-anchor="end"><?= htmlspecialchars(end($B['keys'])) ?></text>
                        <?php endif; ?>
                    </svg>
                    <div class="rs-legend">
                        <span><i style="background:#1B6FD1"></i>Mentees — <?= number_format($runM) ?></span>
                        <span><i style="background:#17654B"></i>Mentors — <?= number_format($runT) ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ── Session activity ── -->
            <div class="ss-card">
                <h2>Session activity</h2>
                <p class="rs-sub">Sessions scheduled per <?= $B['unit'] ?>, split by whether they were completed.</p>
                <?php
                $sKeys = array_keys($sessSeries);
                $sn    = count($sKeys);
                $sPeak = 1;
                foreach ($sessSeries as $v) $sPeak = max($sPeak, $v['completed'] + $v['other']);
                $BW = 500; $BH = 168; $bpadL = 26; $bpadB = 20; $bpadT = 8;
                $slot = $sn > 0 ? ($BW - $bpadL - 8) / $sn : 0;
                $barW = max(3, min(22, $slot * 0.62));
                $by = fn($v) => $BH - $bpadB - ($v / $sPeak) * ($BH - $bpadB - $bpadT);
                ?>
                <?php if ($now['sessions'] === 0): ?>
                    <p class="rs-empty">No sessions fall in this range.</p>
                <?php else: ?>
                    <svg class="rs-chart" viewBox="0 0 <?= $BW ?> <?= $BH ?>" role="img"
                         aria-label="Sessions per <?= $B['unit'] ?>, <?= $now['sessions'] ?> in total">
                        <?php for ($i = 0; $i <= 2; $i++):
                            $val = (int)round($sPeak * $i / 2); $y = $by($val); ?>
                            <line x1="<?= $bpadL ?>" y1="<?= round($y, 1) ?>" x2="<?= $BW - 6 ?>" y2="<?= round($y, 1) ?>" stroke="#EDEDED" stroke-width="1" />
                            <text class="rs-axis" x="<?= $bpadL - 6 ?>" y="<?= round($y + 3, 1) ?>" text-anchor="end"><?= $val ?></text>
                        <?php endfor; ?>
                        <?php $i = 0; foreach ($sessSeries as $k => $v):
                            $cx  = $bpadL + $i * $slot + ($slot - $barW) / 2;
                            $tot = $v['completed'] + $v['other'];
                            $i++;
                            if ($tot === 0) continue;
                            $yTop  = $by($tot);
                            $yDone = $by($v['completed']);
                        ?>
                            <rect x="<?= round($cx, 1) ?>" y="<?= round($yTop, 1) ?>" width="<?= round($barW, 1) ?>"
                                  height="<?= round($BH - $bpadB - $yTop, 1) ?>" rx="2.5" fill="#C7DBF2" />
                            <?php if ($v['completed'] > 0): ?>
                                <rect x="<?= round($cx, 1) ?>" y="<?= round($yDone, 1) ?>" width="<?= round($barW, 1) ?>"
                                      height="<?= round($BH - $bpadB - $yDone, 1) ?>" rx="2.5" fill="#1B6FD1" />
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <text class="rs-axis" x="<?= $bpadL ?>" y="<?= $BH - 5 ?>"><?= htmlspecialchars(reset($B['keys'])) ?></text>
                        <?php if ($sn > 1): ?>
                            <text class="rs-axis" x="<?= $BW - 6 ?>" y="<?= $BH - 5 ?>" text-anchor="end"><?= htmlspecialchars(end($B['keys'])) ?></text>
                        <?php endif; ?>
                    </svg>
                    <div class="rs-legend">
                        <span><i style="background:#1B6FD1"></i>Completed — <?= number_format($now['completed']) ?></span>
                        <span><i style="background:#C7DBF2"></i>Everything else — <?= number_format($now['sessions'] - $now['completed']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="rs-cols">
            <!-- ── Subjects ── -->
            <div class="ss-card">
                <h2>Busiest subjects</h2>
                <p class="rs-sub">What sessions in this range were booked for.</p>
                <?php if (!$subjects): ?>
                    <p class="rs-empty">No sessions were booked in this range.</p>
                <?php else: foreach ($subjects as $s): ?>
                    <div class="rs-bar">
                        <b title="<?= htmlspecialchars($s['subject']) ?>"><?= htmlspecialchars($s['subject']) ?></b>
                        <span class="rs-bar-t"><i style="width:<?= round((int)$s['n'] / $subjectPeak * 100) ?>%;background:#1B6FD1"></i></span>
                        <span class="rs-bar-n"><?= (int)$s['n'] ?></span>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <!-- ── Engagement ── -->
            <div class="ss-card">
                <h2>Engagement</h2>
                <p class="rs-sub">Measured from sign-in records and the work people did.</p>
                <div class="rs-kv">
                    <span class="k">Sign-ins<small>Every sign-in by a member, repeats included</small></span>
                    <b><?= number_format($now['signins']) ?></b>
                </div>
                <div class="rs-kv">
                    <span class="k">Members who signed in<small>Distinct accounts, admins left out</small></span>
                    <b><?= number_format($signedIn) ?></b>
                </div>
                <div class="rs-kv">
                    <span class="k">Came back another day<small><?= $signedIn > 0 ? round($returned / $signedIn * 100) . '% of those who signed in' : 'Nobody signed in' ?></small></span>
                    <b><?= number_format($returned) ?></b>
                </div>
                <div class="rs-kv">
                    <span class="k">Sessions per booking mentee<small><?= $bookingMentees > 0 ? $bookingMentees . ' mentee' . ($bookingMentees === 1 ? '' : 's') . ' booked at least one' : 'Nobody booked a session' ?></small></span>
                    <b><?= $bookingMentees > 0 ? number_format($now['sessions'] / $bookingMentees, 1) : '—' ?></b>
                </div>
                <div class="rs-kv">
                    <span class="k">Average rating given<small><?= (int)$ratingRow['n'] > 0 ? (int)$ratingRow['n'] . ' review' . ((int)$ratingRow['n'] === 1 ? '' : 's') . ' written' : 'No reviews in this range' ?></small></span>
                    <b><?= $avgRating !== null ? number_format($avgRating, 1) . ' / 5' : '—' ?></b>
                </div>
            </div>
        </div>

        <!-- ── Recent activity ── -->
        <div class="ss-card">
            <h2>Recent activity<?php if ($feed): ?><a href="<?= url('admin-settings-logs') ?>">Full sign-in log</a><?php endif; ?></h2>
            <p class="rs-sub">The last things that happened in this range, across every part of the app. Sign-ins are not listed here — there are hundreds of them, and they have a page of their own.</p>
            <?php if (!$feed): ?>
                <p class="rs-empty">Nothing was recorded in this range.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="rs-feed">
                        <thead>
                            <tr><th>When</th><th>Who</th><th>What</th><th>Type</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($feed as $f):
                                [$label, $bg, $fg] = $kinds[$f['kind']] ?? ['Activity', '#F3F4F6', '#4B5563']; ?>
                                <tr>
                                    <td class="dt"><?= date('M j, Y g:i A', strtotime($f['ts'])) ?></td>
                                    <td class="w"><?= htmlspecialchars(trim((string)$f['who']) !== '' ? $f['who'] : 'Deleted account') ?></td>
                                    <td class="de"><?= htmlspecialchars((string)$f['detail']) ?></td>
                                    <td><span class="rs-tag" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= $label ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── The honest gap ── -->
        <div class="ss-card">
            <h2>What this page cannot tell you</h2>
            <div class="rs-gap">
                <b>Four things the reference design showed are missing, on purpose.</b>
                Nothing in this install records them, so any number in their place would be made up.
                <ul>
                    <li><b>Time on site</b> and <b>pages per visit</b> — no page views are recorded. Sign-ins are, and they are counted above.</li>
                    <li><b>Uptime</b> and <b>response time</b> — nothing monitors the server. XAMPP's own logs are the closest thing.</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- ══════════ Side ══════════ -->
    <div class="rs-stack">

        <!-- ── Where the activity is ── -->
        <div class="ss-card">
            <h2>Where the activity is</h2>
            <p class="rs-sub">Records people created in this range, by area. This counts things made, not screens opened — nothing here records a page view.</p>
            <?php if ($areaTotal === 0): ?>
                <p class="rs-empty">Nothing was created in this range.</p>
            <?php else:
                $R_ = 34; $SW = 15; $C = 2 * M_PI * $R_; $off = 0; ?>
                <div class="rs-donut">
                    <div style="position:relative;flex:none;width:92px;height:92px;">
                        <svg width="92" height="92" viewBox="0 0 92 92" role="img" aria-label="Share of records created, by area">
                            <circle cx="46" cy="46" r="<?= $R_ ?>" fill="none" stroke="#EDEDED" stroke-width="<?= $SW ?>" />
                            <?php foreach ($areas as [$name, $val, $col]):
                                if ($val === 0) continue;
                                $len = $C * $val / $areaTotal; ?>
                                <circle cx="46" cy="46" r="<?= $R_ ?>" fill="none" stroke="<?= $col ?>" stroke-width="<?= $SW ?>"
                                        stroke-dasharray="<?= round($len, 2) ?> <?= round($C - $len, 2) ?>"
                                        stroke-dashoffset="<?= round(-$off, 2) ?>" transform="rotate(-90 46 46)" />
                                <?php $off += $len; ?>
                            <?php endforeach; ?>
                        </svg>
                        <div class="rs-donut-mid">
                            <div>
                                <b><?= number_format($areaTotal) ?></b>
                                <span>records</span>
                            </div>
                        </div>
                    </div>
                    <div class="rs-keys">
                        <?php foreach ($areas as [$name, $val, $col]): if ($val === 0) continue; ?>
                            <span class="rs-key">
                                <i style="background:<?= $col ?>"></i><?= $name ?>
                                <b><?= number_format($val) ?></b>
                                <span class="p"><?= round($val / $areaTotal * 100) ?>%</span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── Member mix ── -->
        <div class="ss-card">
            <h2>Member mix</h2>
            <p class="rs-sub">Every account on the platform right now, whenever it was created.</p>
            <?php
            $mixCols = ['mentee' => '#1B6FD1', 'mentor' => '#17654B', 'admin' => '#5A3E96', 'unassigned' => '#B0B5BF'];
            $mixTotal = array_sum($roleMix);
            $R2 = 34; $SW2 = 15; $C2 = 2 * M_PI * $R2; $off2 = 0;
            ?>
            <div class="rs-donut">
                <div style="position:relative;flex:none;width:92px;height:92px;">
                    <svg width="92" height="92" viewBox="0 0 92 92" role="img" aria-label="Accounts by role">
                        <circle cx="46" cy="46" r="<?= $R2 ?>" fill="none" stroke="#EDEDED" stroke-width="<?= $SW2 ?>" />
                        <?php foreach ($roleMix as $role => $val):
                            if ($val === 0) continue;
                            $len = $C2 * $val / max(1, $mixTotal); ?>
                            <circle cx="46" cy="46" r="<?= $R2 ?>" fill="none" stroke="<?= $mixCols[$role] ?? '#B0B5BF' ?>" stroke-width="<?= $SW2 ?>"
                                    stroke-dasharray="<?= round($len, 2) ?> <?= round($C2 - $len, 2) ?>"
                                    stroke-dashoffset="<?= round(-$off2, 2) ?>" transform="rotate(-90 46 46)" />
                            <?php $off2 += $len; ?>
                        <?php endforeach; ?>
                    </svg>
                    <div class="rs-donut-mid">
                        <div>
                            <b><?= number_format($mixTotal) ?></b>
                            <span>accounts</span>
                        </div>
                    </div>
                </div>
                <div class="rs-keys">
                    <?php foreach ($roleMix as $role => $val): ?>
                        <span class="rs-key">
                            <i style="background:<?= $mixCols[$role] ?? '#B0B5BF' ?>"></i><?= ucfirst($role) ?>
                            <b><?= number_format($val) ?></b>
                            <span class="p"><?= $mixTotal > 0 ? round($val / $mixTotal * 100) : 0 ?>%</span>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if (isset($roleMix['unassigned'])): ?>
                <p class="rs-sub" style="margin:12px 0 0;">
                    <?= $roleMix['unassigned'] ?> account<?= $roleMix['unassigned'] === 1 ? ' has' : 's have' ?> no role set —
                    <a href="<?= url('admin-users') ?>" style="color:var(--mint);font-weight:600;text-decoration:none;">review in Users</a>.
                </p>
            <?php endif; ?>
        </div>

        <!-- ── Observations ── -->
        <?php if ($notes): ?>
            <div class="ss-card">
                <h2>What stands out</h2>
                <p class="rs-sub">Read off the figures above — nothing here is a prediction.</p>
                <?php foreach ($notes as [$tone, $text]):
                    $glyph = ['up' => '↑', 'down' => '↓', 'warn' => '!', 'info' => 'i'][$tone] ?? 'i'; ?>
                    <div class="rs-note <?= $tone ?>"><i><?= $glyph ?></i><span><?= htmlspecialchars($text) ?></span></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ── Waiting on you ── -->
        <div class="ss-card">
            <h2>Waiting on an admin</h2>
            <p class="rs-sub">Current queues, not affected by the date range.</p>
            <?php foreach ($queue as [$label, $n, $href]): ?>
                <a class="rs-q <?= $n > 0 ? 'hot' : '' ?>" href="<?= $href ?>"><?= $label ?><b><?= number_format($n) ?></b></a>
            <?php endforeach; ?>
        </div>

        <!-- ── Health ── -->
        <div class="ss-card">
            <h2>Storage &amp; delivery</h2>
            <p class="rs-sub">What the platform is holding, and whether its email is getting out.</p>
            <div class="rs-kv">
                <span class="k">Database<small><?= count($roleMix) ? number_format($totalMembers) . ' member accounts' : '' ?></small></span>
                <b><?= rp_size($dbBytes) ?></b>
            </div>
            <div class="rs-kv">
                <span class="k">Uploaded files<small><?= number_format($upN) ?> file<?= $upN === 1 ? '' : 's' ?> in public/uploads</small></span>
                <b><?= rp_size($upB) ?></b>
            </div>
            <div class="rs-kv">
                <span class="k">Private files<small><?= number_format($privN) ?> file<?= $privN === 1 ? '' : 's' ?> in storage — verification documents and report images</small></span>
                <b><?= rp_size($privB) ?></b>
            </div>
            <div class="rs-kv">
                <span class="k">Notification email<small><?= $mailTotal > 0
                    ? $mail['sent'] . ' sent · ' . $mail['failed'] . ' failed · ' . $mail['pending'] . ' pending · ' . $mail['skipped'] . ' skipped'
                    : 'No notifications raised in this range' ?></small></span>
                <b><?= $mailTotal > 0 ? round($mail['sent'] / $mailTotal * 100) . '%' : '—' ?></b>
            </div>
        </div>

    </div>
</div>
