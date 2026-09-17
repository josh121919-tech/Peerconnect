<?php

/**
 * admin/sessions_reports.php — Session Reports.
 *
 * The numbers behind the sessions: how many, how they ended, how long they
 * ran, what they were about, who showed up and what people said afterwards.
 *
 * Everything here is computed from session_requests, availability, feedback,
 * mentee_reviews and missed_session_logs over the chosen date range. Where a
 * figure cannot be computed honestly — no concluded sessions yet, nobody has
 * rated anything — the card says so instead of showing a zero that reads like
 * a real measurement.
 *
 * The reference offered PDF and Excel export. Nothing in this install can
 * render a PDF, so the export is CSV, which opens in both Excel and Sheets.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/session_data.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$STATES = ad_session_states();

/* ── Range ────────────────────────────────────────────────────────────── */
$presets = [
    '30d'   => 'Last 30 days',
    '90d'   => 'Last 90 days',
    'month' => 'This month',
    'year'  => 'This year',
    'all'   => 'All time',
];
$range = array_key_exists(ad_query('range'), $presets) ? ad_query('range') : '30d';

switch ($range) {
    case '90d':   $from = date('Y-m-d', strtotime('-89 days')); $to = date('Y-m-d'); break;
    case 'month': $from = date('Y-m-01'); $to = date('Y-m-t'); break;
    case 'year':  $from = date('Y-01-01'); $to = date('Y-12-31'); break;
    case 'all':   $from = null; $to = null; break;
    default:      $from = date('Y-m-d', strtotime('-29 days')); $to = date('Y-m-d');
}

$rangeLabel = $from ? date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to)) : 'All time';

/* ── Headline figures ─────────────────────────────────────────────────── */
$figures   = AdminSessionRepository::rangeFigures($con, $from, $to);
$total     = $figures['total'];
$completed = $figures['completed'];
$cancelled = $figures['cancelled'];
$missed    = $figures['missed'];
$upcoming  = $figures['upcoming'];
$notClosed = $figures['not_closed'];
$pending   = $figures['pending'];

$concluded  = $completed + $cancelled + $missed;
$completion = $concluded > 0 ? round($completed / $concluded * 100, 1) : null;

// Average length comes from the availability slot each session was booked
// against; sessions whose slot has since been deleted are left out rather
// than counted as the fallback hour, which would drag the average.
$length  = AdminSessionRepository::averageMinutes($con, $from, $to);
$avgMin  = $length['minutes'] !== null ? (int)round($length['minutes']) : null;
$avgFrom = $length['sessions'];

/* Ratings across both directions, for sessions in range. */
$rated     = FeedbackRepository::ratingSummaryBetween($con, $from, $to);
$ratingN   = $rated['reviews'];
$avgRating = $ratingN > 0 ? round((float)$rated['average'], 1) : null;

$dist = FeedbackRepository::ratingDistributionBetween($con, $from, $to);

/* ── Sessions over time ───────────────────────────────────────────────── */
$days = [];
if ($from) {
    $cur = strtotime($from);
    while ($cur <= strtotime($to)) { $days[date('Y-m-d', $cur)] = ['completed' => 0, 'upcoming' => 0, 'cancelled' => 0]; $cur = strtotime('+1 day', $cur); }
} else {
    // All time: fall back to the last 30 days of activity so the chart stays legible.
    $cur = strtotime('-29 days');
    while ($cur <= time()) { $days[date('Y-m-d', $cur)] = ['completed' => 0, 'upcoming' => 0, 'cancelled' => 0]; $cur = strtotime('+1 day', $cur); }
}
$first = array_key_first($days);
$last  = array_key_last($days);
foreach (AdminSessionRepository::dailyStatusCounts($con, $first, $last) as $x) {
    if (!isset($days[$x['d']])) continue;
    $bucket = in_array($x['status'], ['completed'], true) ? 'completed'
        : (in_array($x['status'], ['cancelled', 'rejected', 'missed'], true) ? 'cancelled' : 'upcoming');
    $days[$x['d']][$bucket] += (int)$x['c'];
}

/* ── Subjects ─────────────────────────────────────────────────────────── */
$subjects = AdminSessionRepository::subjectTotals($con, $from, $to, 6);
$subjectTotal = array_sum(array_column($subjects, 'c'));

/* ── Attendance ───────────────────────────────────────────────────────── */
// Attendance is only meaningful over sessions that reached their time and
// were then closed one way or the other.
$attTotal   = $completed + $missed;
$mentorMiss = $figures['mentor_missed'];
$menteeMiss = $figures['mentee_missed'];
$mentorRate = $attTotal > 0 ? round(($attTotal - $mentorMiss) / $attTotal * 100) : null;
$menteeRate = $attTotal > 0 ? round(($attTotal - $menteeMiss) / $attTotal * 100) : null;

/* ── Top mentors ──────────────────────────────────────────────────────── */
$topMentors = AdminSessionRepository::topMentors($con, $from, $to, 5);

/* ── Recent sessions ──────────────────────────────────────────────────── */
$recent = AdminSessionRepository::recent($con, $from, $to, 5);

/* ── Distribution, for the donut ──────────────────────────────────────── */
/*
 * Every session in range has to land in exactly one slice, or the middle of
 * the donut disagrees with the "Sessions" tile above it. 'Not closed' is the
 * slice that is easy to forget: accepted, its time has passed, and nobody
 * marked it either way.
 */
$donut = array_filter([
    'Completed'  => [$completed, '#1B6FD1'],
    'Upcoming'   => [$upcoming,  '#17654B'],
    'Pending'    => [$pending,   '#B7791F'],
    'Not closed' => [$notClosed, '#9A4A00'],
    'Cancelled'  => [$cancelled, '#C0392B'],
    'Missed'     => [$missed,    '#6B21A8'],
], fn($v) => $v[0] > 0);

$exportQs = http_build_query(array_filter(['from' => $from, 'to' => $to], fn($v) => $v !== null));

$current_page = 'sessions-reports';
include 'layout.php';
include __DIR__ . '/includes/sessions_ui.php';
?>

<style>
    .rp-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 14px; align-items: start; }
    .rp-cols { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
    .rp-cols-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
    .rp-stack { display: flex; flex-direction: column; gap: 14px; }

    .rp-line { width: 100%; height: 190px; display: block; }
    .rp-legend { display: flex; gap: 14px; flex-wrap: wrap; font-size: 12px; color: var(--gray-500); margin-top: 8px; }
    .rp-legend span { display: inline-flex; align-items: center; gap: 6px; }
    .rp-legend i { width: 9px; height: 9px; border-radius: 50%; }

    .rp-donut { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
    .rp-donut-n { position: relative; flex: none; }
    .rp-donut-c { position: absolute; inset: 0; display: grid; place-items: center; text-align: center; }
    .rp-donut-c b { display: block; font-size: 22px; font-weight: 700; color: var(--forest); line-height: 1.1; }
    .rp-donut-c span { font-size: 11px; color: var(--gray-400); }
    .rp-donut-l { flex: 1; min-width: 150px; display: flex; flex-direction: column; gap: 8px; }
    .rp-donut-l div { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--gray-600); }
    .rp-donut-l i { width: 9px; height: 9px; border-radius: 50%; flex: none; }
    .rp-donut-l b { margin-left: auto; font-weight: 700; color: var(--forest); font-variant-numeric: tabular-nums; }

    .rp-bars { display: flex; flex-direction: column; gap: 10px; }
    .rp-bar { display: flex; align-items: center; gap: 10px; font-size: 12.5px; color: var(--gray-600); }
    .rp-bar > span:first-child { width: 116px; flex: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rp-bar-t { flex: 1; height: 8px; border-radius: 999px; background: var(--gray-100); overflow: hidden; }
    .rp-bar-t i { display: block; height: 100%; border-radius: 999px; }
    .rp-bar-n { flex: none; width: 42px; text-align: right; font-variant-numeric: tabular-nums; color: var(--gray-500); }

    .rp-ring { display: flex; flex-direction: column; align-items: center; gap: 8px; }
    .rp-ring b { font-size: 13px; font-weight: 700; color: var(--forest); }
    .rp-ring span { font-size: 11.5px; color: var(--gray-400); }

    .rp-big { text-align: center; }
    .rp-big b { display: block; font-size: 34px; font-weight: 700; color: var(--forest); line-height: 1.1; }
    .rp-big i { color: #E5A800; font-style: normal; letter-spacing: 2px; font-size: 16px; }
    .rp-big span { display: block; font-size: 12px; color: var(--gray-400); margin-top: 3px; }

    .rp-side .ss-card { padding: 16px 17px; }
    .rp-field { margin-bottom: 12px; }
    .rp-field label { display: block; font-size: 12px; font-weight: 600; color: var(--gray-600); margin-bottom: 5px; }
    .rp-field select, .rp-field input {
        width: 100%; height: 38px; padding: 0 11px; border: 1px solid var(--gray-200);
        border-radius: 10px; font-family: inherit; font-size: 13px; color: var(--gray-700); background: #fff;
    }
    .rp-go { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 11px; border: 0; border-radius: 11px; background: var(--forest); color: #fff; font-family: inherit; font-size: 13.5px; font-weight: 600; cursor: pointer; text-decoration: none; }
    .rp-go:hover { background: #0B1440; }

    .rp-insight { display: flex; align-items: flex-start; gap: 11px; padding: 11px 0; border-top: 1px solid var(--gray-100); }
    .rp-insight:first-of-type { border-top: 0; padding-top: 0; }
    .rp-insight-i { width: 34px; height: 34px; flex: none; border-radius: 10px; display: grid; place-items: center; }
    .rp-insight-i svg { width: 17px; height: 17px; }
    .rp-insight b { display: block; font-size: 15px; font-weight: 700; color: var(--forest); }
    .rp-insight span { font-size: 12px; color: var(--gray-400); }

    .rp-top { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-top: 1px solid var(--gray-100); text-decoration: none; }
    .rp-top:first-of-type { border-top: 0; }
    .rp-top-n { width: 18px; flex: none; font-size: 12px; font-weight: 700; color: var(--gray-400); }
    .rp-top b { flex: 1; min-width: 0; font-size: 13px; font-weight: 600; color: var(--gray-800); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rp-top-s { font-size: 12px; color: #B7791F; font-weight: 600; }
    .rp-top-c { font-size: 11.5px; color: var(--gray-400); }

    @media (max-width: 1240px) { .rp-grid { grid-template-columns: minmax(0, 1fr); } }
    @media (max-width: 900px) { .rp-cols, .rp-cols-3 { grid-template-columns: minmax(0, 1fr); } }
</style>

<div class="ss-hd">
    <div>
        <h1>Session reports</h1>
        <p>Sessions, engagement, attendance and outcomes over <?= htmlspecialchars(strtolower($rangeLabel)) ?>.</p>
    </div>
    <div class="ss-hd-actions">
        <a class="ss-export" href="<?= url('admin-sessions-export') . ($exportQs ? '?' . $exportQs : '') ?>">
            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0 4-4m-4 4-4-4M4 19h16" /></svg>
            Export CSV
        </a>
    </div>
</div>

<!-- ══════════ Figures ══════════ -->
<div class="ss-stats">
    <?php foreach ([
        ['Sessions', number_format($total), $rangeLabel, '#EAF1FB', '#1A5C9A', 'cal'],
        ['Completed', number_format($completed), $completion !== null ? $completion . '% of concluded' : 'None concluded yet', '#E6F5EE', '#17654B', 'check'],
        ['Average length', $avgMin !== null ? $avgMin . ' min' : '—', $avgMin !== null ? 'From ' . $avgFrom . ' session' . ($avgFrom === 1 ? '' : 's') . ' with a saved slot' : 'No slot data in range', '#EAF6FB', '#0087CF', 'clock'],
        ['Average rating', $avgRating !== null ? number_format($avgRating, 1) . ' / 5' : '—', $ratingN > 0 ? 'From ' . $ratingN . ' review' . ($ratingN === 1 ? '' : 's') : 'No reviews in range', '#FEF6DC', '#B7791F', 'star'],
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

<div class="rp-grid">
    <div class="rp-stack">

        <div class="rp-cols">
            <!-- ── Sessions over time ── -->
            <div class="ss-card">
                <h2>Sessions over time</h2>
                <?php
                $vals = array_values($days);
                $labels = array_keys($days);
                $n = count($vals);
                $peak = 1;
                foreach ($vals as $v) $peak = max($peak, $v['completed'], $v['upcoming'], $v['cancelled']);
                $W = 520; $H = 170; $padL = 28; $padB = 22;
                $x = fn($i) => $padL + ($n > 1 ? $i * (($W - $padL - 8) / ($n - 1)) : 0);
                $y = fn($v) => $H - $padB - ($v / $peak) * ($H - $padB - 10);
                $series = ['completed' => '#1B6FD1', 'upcoming' => '#17654B', 'cancelled' => '#C0392B'];
                ?>
                <?php if ($total === 0): ?>
                    <p class="ss-none">No sessions fall in this range.</p>
                <?php else: ?>
                    <svg class="rp-line" viewBox="0 0 <?= $W ?> <?= $H ?>" preserveAspectRatio="none" role="img"
                         aria-label="Sessions per day, completed, upcoming and cancelled">
                        <?php for ($g = 0; $g <= 4; $g++):
                            $gy = 10 + $g * (($H - $padB - 10) / 4); ?>
                            <line x1="<?= $padL ?>" y1="<?= round($gy, 1) ?>" x2="<?= $W - 8 ?>" y2="<?= round($gy, 1) ?>" stroke="#EDEDED" stroke-width="1" />
                            <text x="<?= $padL - 6 ?>" y="<?= round($gy + 3.5, 1) ?>" text-anchor="end" font-size="9" fill="#9A9EA6"><?= round($peak - $g * $peak / 4) ?></text>
                        <?php endfor; ?>
                        <?php foreach ($series as $key => $col):
                            $pts = [];
                            foreach ($vals as $i => $v) $pts[] = round($x($i), 1) . ',' . round($y($v[$key]), 1);
                        ?>
                            <polyline fill="none" stroke="<?= $col ?>" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="<?= implode(' ', $pts) ?>" />
                        <?php endforeach; ?>
                        <?php
                        // Label the ends and the middle only — a label per day is unreadable.
                        foreach ([0, intdiv($n - 1, 2), $n - 1] as $i):
                            if (!isset($labels[$i])) continue; ?>
                            <text x="<?= round($x($i), 1) ?>" y="<?= $H - 6 ?>" text-anchor="<?= $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle') ?>" font-size="9" fill="#9A9EA6"><?= date('M j', strtotime($labels[$i])) ?></text>
                        <?php endforeach; ?>
                    </svg>
                    <div class="rp-legend">
                        <span><i style="background:#1B6FD1"></i>Completed</span>
                        <span><i style="background:#17654B"></i>Scheduled</span>
                        <span><i style="background:#C0392B"></i>Cancelled or missed</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ── Distribution ── -->
            <div class="ss-card">
                <h2>How sessions ended</h2>
                <?php if (!$donut): ?>
                    <p class="ss-none">No sessions fall in this range.</p>
                <?php else: ?>
                    <div class="rp-donut">
                        <div class="rp-donut-n">
                            <?php
                            $R = 54; $SW = 16; $C = 2 * M_PI * $R; $off = 0;
                            $sum = array_sum(array_map(fn($v) => $v[0], $donut));
                            ?>
                            <svg width="140" height="140" viewBox="0 0 140 140" role="img" aria-label="Share of sessions by outcome">
                                <circle cx="70" cy="70" r="<?= $R ?>" fill="none" stroke="#EDEDED" stroke-width="<?= $SW ?>" />
                                <?php foreach ($donut as $lbl => [$v, $col]):
                                    $len = $C * ($v / $sum); ?>
                                    <circle cx="70" cy="70" r="<?= $R ?>" fill="none" stroke="<?= $col ?>" stroke-width="<?= $SW ?>"
                                            stroke-dasharray="<?= round($len, 2) ?> <?= round($C - $len, 2) ?>"
                                            stroke-dashoffset="<?= round(-$off, 2) ?>"
                                            transform="rotate(-90 70 70)" />
                                    <?php $off += $len; ?>
                                <?php endforeach; ?>
                            </svg>
                            <div class="rp-donut-c"><b><?= number_format($sum) ?></b><span>sessions</span></div>
                        </div>
                        <div class="rp-donut-l">
                            <?php foreach ($donut as $lbl => [$v, $col]): ?>
                                <div><i style="background:<?= $col ?>"></i><?= $lbl ?><b><?= round($v / $sum * 100, 1) ?>%</b></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="rp-cols-3">
            <!-- ── Subjects ── -->
            <div class="ss-card">
                <h2>Popular subjects</h2>
                <?php if (!$subjects): ?>
                    <p class="ss-none">No subjects recorded in this range.</p>
                <?php else: ?>
                    <div class="rp-bars">
                        <?php $cols = ['#1B6FD1', '#17654B', '#6B21A8', '#B7791F', '#C0392B', '#0087CF'];
                        foreach ($subjects as $i => $s):
                            $pct = $subjectTotal > 0 ? round($s['c'] / $subjectTotal * 100) : 0; ?>
                            <div class="rp-bar">
                                <span title="<?= htmlspecialchars($s['subject']) ?>"><?= htmlspecialchars($s['subject']) ?></span>
                                <span class="rp-bar-t"><i style="width:<?= $pct ?>%;background:<?= $cols[$i % count($cols)] ?>"></i></span>
                                <span class="rp-bar-n"><?= $pct ?>%</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ── Attendance ── -->
            <div class="ss-card">
                <h2>Attendance</h2>
                <?php if ($attTotal === 0): ?>
                    <p class="ss-none">No sessions have concluded in this range, so there is no attendance to measure yet.</p>
                <?php else: ?>
                    <div style="display:flex;gap:18px;justify-content:space-around;">
                        <?php foreach ([['Mentor', $mentorRate], ['Mentee', $menteeRate]] as [$who, $rate]):
                            $R = 34; $C = 2 * M_PI * $R; $len = $C * ($rate / 100); ?>
                            <div class="rp-ring">
                                <svg width="86" height="86" viewBox="0 0 86 86" role="img" aria-label="<?= $who ?> attendance <?= $rate ?> percent">
                                    <circle cx="43" cy="43" r="<?= $R ?>" fill="none" stroke="#EDEDED" stroke-width="9" />
                                    <circle cx="43" cy="43" r="<?= $R ?>" fill="none" stroke="#1B6FD1" stroke-width="9" stroke-linecap="round"
                                            stroke-dasharray="<?= round($len, 2) ?> <?= round($C - $len, 2) ?>" transform="rotate(-90 43 43)" />
                                    <text x="43" y="47" text-anchor="middle" font-size="16" font-weight="700" fill="#020547"><?= $rate ?>%</text>
                                </svg>
                                <b><?= $who ?></b>
                                <span>attendance</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="ss-none" style="margin-top:10px;text-align:center;">Across <?= $attTotal ?> concluded session<?= $attTotal === 1 ? '' : 's' ?>.</p>
                <?php endif; ?>
            </div>

            <!-- ── Feedback ── -->
            <div class="ss-card">
                <h2>Feedback</h2>
                <?php if ($ratingN === 0): ?>
                    <p class="ss-none">Nobody has left a rating in this range.</p>
                <?php else: ?>
                    <div class="rp-big">
                        <b><?= number_format($avgRating, 1) ?><span style="font-size:16px;color:var(--gray-400);font-weight:600;"> / 5</span></b>
                        <i><?= str_repeat('★', (int)round($avgRating)) . str_repeat('☆', max(0, 5 - (int)round($avgRating))) ?></i>
                        <span>Based on <?= $ratingN ?> review<?= $ratingN === 1 ? '' : 's' ?></span>
                    </div>
                    <div class="rp-bars" style="margin-top:14px;">
                        <?php for ($sN = 5; $sN >= 1; $sN--):
                            $pct = $ratingN > 0 ? round($dist[$sN] / $ratingN * 100) : 0; ?>
                            <div class="rp-bar">
                                <span style="width:52px;"><?= $sN ?> star<?= $sN === 1 ? '' : 's' ?></span>
                                <span class="rp-bar-t"><i style="width:<?= $pct ?>%;background:#1B6FD1"></i></span>
                                <span class="rp-bar-n"><?= $pct ?>%</span>
                            </div>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Recent ── -->
        <div class="ss-card">
            <h2>Recent sessions <a href="<?= url('admin-sessions') ?>">View all →</a></h2>
            <?php if (!$recent): ?>
                <p class="ss-none">No sessions in this range.</p>
            <?php else: ?>
                <div class="ss-list">
                    <?php foreach ($recent as $s):
                        $st = ad_session_state($s);
                        [$l, $fg, $bg] = $STATES[$st];
                        $id = (int)$s['request_id']; ?>
                        <div class="ss-row" style="box-shadow:none;">
                            <div class="ss-date">
                                <span><?= strtoupper(date('M', strtotime($s['session_date']))) ?></span>
                                <b><?= date('d', strtotime($s['session_date'])) ?></b>
                                <span><?= date('Y', strtotime($s['session_date'])) ?></span>
                            </div>
                            <div class="ss-what">
                                <div class="ss-title"><?= htmlspecialchars($s['subject'] ?: 'Mentoring session') ?></div>
                                <div class="ss-ref">#<?= ad_session_ref($id, $s['session_date']) ?></div>
                            </div>
                            <div class="ss-people">
                                <?= ss_person($s['mentor_pic'], $s['mentor_name'], 'Mentor', $s['mentor_rating']) ?>
                                <span class="ss-swap"><?= ss_icon('swap') ?></span>
                                <?= ss_person($s['mentee_pic'], $s['mentee_name'], 'Mentee', $s['mentee_rating']) ?>
                            </div>
                            <div class="ss-when">
                                <span><?= ss_icon('clock') ?><?= ad_time_range($s) ?></span>
                                <span class="ss-dur"><?= ad_duration_label(ad_session_minutes($s)) ?></span>
                            </div>
                            <div class="ss-end">
                                <span class="ss-pill" style="color:<?= $fg ?>;background:<?= $bg ?>;"><?= $l ?></span>
                                <a class="ss-view" href="<?= url('admin-sessions') ?>?open=<?= $id ?>#panel">View report</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══════════ Side ══════════ -->
    <div class="rp-side rp-stack">
        <div class="ss-card">
            <h2>Build a report</h2>
            <form method="get" action="<?= url('admin-sessions-reports') ?>">
                <div class="rp-field">
                    <label for="rp-range">Date range</label>
                    <select id="rp-range" name="range">
                        <?php foreach ($presets as $k => $l): ?>
                            <option value="<?= $k ?>" <?= $range === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="rp-go">Update report</button>
            </form>
            <a class="rp-go" style="background:#fff;color:var(--gray-700);border:1px solid var(--gray-200);margin-top:9px;"
               href="<?= url('admin-sessions-export') . ($exportQs ? '?' . $exportQs : '') ?>">Download this range as CSV</a>
            <p class="ss-none" style="margin-top:9px;">CSV opens in Excel and Google Sheets. PDF export is not available — this install has no PDF renderer.</p>
        </div>

        <div class="ss-card">
            <h2>At a glance</h2>
            <?php
            $insights = [];
            if ($completion !== null) $insights[] = [$completion . '%', 'of concluded sessions were completed', '#E6F5EE', '#17654B', 'check'];
            if ($avgMin !== null)     $insights[] = [$avgMin . ' min', 'average session length', '#EAF6FB', '#0087CF', 'clock'];
            if ($avgRating !== null)  $insights[] = [number_format($avgRating, 1) . ' / 5', 'average rating from ' . $ratingN . ' review' . ($ratingN === 1 ? '' : 's'), '#FEF6DC', '#B7791F', 'star'];
            if ($concluded > 0)       $insights[] = [round($cancelled / max(1, $total) * 100, 1) . '%', 'of sessions were cancelled or declined', '#FBE5E1', '#A6301F', 'x'];
            $insights[] = [number_format($upcoming), 'session' . ($upcoming === 1 ? '' : 's') . ' still to come', '#EAF1FB', '#1A5C9A', 'cal'];
            foreach ($insights as [$big, $sub, $bg, $fg, $ico]): ?>
                <div class="rp-insight">
                    <span class="rp-insight-i" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= ss_icon($ico) ?></span>
                    <span style="min-width:0;"><b><?= $big ?></b><span><?= htmlspecialchars($sub) ?></span></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="ss-card">
            <h2>Most sessions delivered <a href="<?= url('admin-users') ?>?role=mentor">All mentors →</a></h2>
            <?php if (!$topMentors): ?>
                <p class="ss-none">No sessions have been completed in this range.</p>
            <?php else: foreach ($topMentors as $i => $m): ?>
                <a class="rp-top" href="<?= url('admin-user') ?>?id=<?= (int)$m['user_id'] ?>">
                    <span class="rp-top-n"><?= $i + 1 ?></span>
                    <span class="ss-av" style="width:30px;height:30px;font-size:10.5px;">
                        <?= $m['profile_image'] ? '<img src="' . htmlspecialchars($m['profile_image']) . '" alt="">' : htmlspecialchars(strtoupper(substr($m['nm'], 0, 2))) ?>
                    </span>
                    <b><?= htmlspecialchars($m['nm']) ?></b>
                    <?php if ($m['rating'] !== null): ?><span class="rp-top-s">★ <?= number_format((float)$m['rating'], 1) ?></span><?php endif; ?>
                    <span class="rp-top-c"><?= (int)$m['sessions'] ?></span>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>
