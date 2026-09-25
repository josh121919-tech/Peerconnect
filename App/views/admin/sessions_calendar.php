<?php

/**
 * admin/sessions_calendar.php — Calendar View.
 *
 * The same sessions as the list, laid out against time, which is the view
 * that answers questions the list cannot: what is happening this afternoon,
 * where the clashes are, which days are empty.
 *
 * Month, Week and Day are three shapes of one query. The side panel lists the
 * selected day in full, with the same actions the list page offers, so an
 * admin who spots a problem here does not have to go and find the session
 * somewhere else.
 *
 * The reference showed an "Add Session for this Date" button. An admin cannot
 * create a booking on someone's behalf in this app — a session belongs to a
 * mentor's availability slot and a mentee's request — so that button is not
 * drawn rather than drawn dead.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/session_data.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$STATES = ad_session_states();

/* ── What is being shown ──────────────────────────────────────────────── */
$modes = ['month', 'week', 'day'];
$mode  = in_array($_GET['mode'] ?? '', $modes, true) ? $_GET['mode'] : 'week';

$focus = ad_query('d');
$focusTs = ($focus !== '' && strtotime($focus)) ? strtotime($focus) : time();
$focusDay = date('Y-m-d', $focusTs);

$sel = ad_query('sel');
$selected = $sel !== '' && strtotime($sel) ? date('Y-m-d', strtotime($sel)) : $focusDay;

/* Filters, matching the list page's vocabulary. */
$fStatus  = in_array($_GET['status'] ?? '', array_keys($STATES), true) ? $_GET['status'] : '';
$fType    = in_array($_GET['type'] ?? '', ['1v1', 'group'], true) ? $_GET['type'] : '';
$fSubject = trim(ad_query('subject'));
$fClub    = trim(ad_query('club'));
$fMentor  = (int)($_GET['mentor'] ?? 0);

/* ── The window each mode covers ──────────────────────────────────────── */
if ($mode === 'day') {
    $rangeStart = $focusDay;
    $rangeEnd   = $focusDay;
    $heading    = date('F j, Y', $focusTs);
} elseif ($mode === 'week') {
    // Weeks run Sunday to Saturday, matching the reference.
    $dow        = (int)date('w', $focusTs);
    $rangeStart = date('Y-m-d', strtotime("-$dow days", $focusTs));
    $rangeEnd   = date('Y-m-d', strtotime('+6 days', strtotime($rangeStart)));
    $heading    = date('M j', strtotime($rangeStart)) . ' – ' . date('M j, Y', strtotime($rangeEnd));
} else {
    $first      = date('Y-m-01', $focusTs);
    $rangeStart = date('Y-m-d', strtotime('-' . (int)date('w', strtotime($first)) . ' days', strtotime($first)));
    $lastDay    = date('Y-m-t', $focusTs);
    $tail       = 6 - (int)date('w', strtotime($lastDay));
    $rangeEnd   = date('Y-m-d', strtotime("+$tail days", strtotime($lastDay)));
    $heading    = date('F Y', $focusTs);
}

/* ── Load the window ──────────────────────────────────────────────────── */
$all = AdminSessionRepository::inRange($con, $rangeStart, $rangeEnd, [
    'type'    => $fType,
    'subject' => $fSubject,
    'club'    => $fClub,
    'mentor'  => $fMentor,
], $fStatus);

/* Bucket by day, and work out the hour band the grid needs to cover. */
$byDay = [];
$minH = 23;
$maxH = 0;
foreach ($all as $s) {
    $d = date('Y-m-d', strtotime($s['session_date']));
    $byDay[$d][] = $s;
    $h  = (int)date('G', strtotime($s['session_date']));
    $eh = (int)date('G', strtotime($s['session_date']) + ad_session_minutes($s) * 60);
    $minH = min($minH, $h);
    $maxH = max($maxH, $eh);
}
// A sensible default band when the window is empty, and a little air either side.
if (!$all) { $minH = 8; $maxH = 18; }
$minH = max(0, min($minH, 8));
$maxH = min(23, max($maxH + 1, 18));
$hours = range($minH, $maxH);

$dayList = $byDay[$selected] ?? [];

/* ── Filter options ───────────────────────────────────────────────────── */
$clubs    = AdminSessionRepository::clubs($con);
$mentors  = AdminSessionRepository::mentorsWithSessions($con);

/** Keep mode and filters when moving around. */
function cal_url(array $over = []): string
{
    $p = array_merge([
        'mode' => $_GET['mode'] ?? null, 'd' => $_GET['d'] ?? null, 'sel' => $_GET['sel'] ?? null,
        'status' => $_GET['status'] ?? null, 'type' => $_GET['type'] ?? null,
        'subject' => $_GET['subject'] ?? null, 'club' => $_GET['club'] ?? null,
        'mentor' => $_GET['mentor'] ?? null,
    ], $over);
    $p = array_filter($p, fn($v) => $v !== null && $v !== '');
    return url('admin-sessions-calendar') . ($p ? '?' . http_build_query($p) : '');
}

$step = $mode === 'day' ? '1 day' : ($mode === 'week' ? '1 week' : '1 month');
$prev = date('Y-m-d', strtotime("-$step", $focusTs));
$next = date('Y-m-d', strtotime("+$step", $focusTs));
$hasFilter = ($fStatus !== '' || $fType !== '' || $fSubject !== '' || $fClub !== '' || $fMentor > 0);

$csrf = csrf_token();
$current_page = 'sessions-calendar';
include 'layout.php';
include __DIR__ . '/includes/sessions_ui.php';
?>

<style>
    .cv-wrap { display: flex; gap: 16px; align-items: flex-start; }
    .cv-main { flex: 1; min-width: 0; }

    .cv-bar { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; margin-bottom: 14px; }
    .cv-nav { display: flex; align-items: center; gap: 5px; }
    .cv-btn {
        display: inline-flex; align-items: center; gap: 7px; height: 38px; padding: 0 14px;
        border: 1px solid var(--gray-200); border-radius: 10px; background: #fff;
        font-family: inherit; font-size: 13px; font-weight: 600; color: var(--gray-700); text-decoration: none;
    }
    .cv-btn:hover { border-color: var(--mint); color: var(--mint-deep, #00539B); }
    .cv-btn.sq { width: 38px; padding: 0; justify-content: center; }
    .cv-title { font-size: 15px; font-weight: 700; color: var(--forest); padding: 0 6px; }
    .cv-modes { margin-left: auto; display: flex; padding: 3px; border: 1px solid var(--gray-200); border-radius: 11px; background: #fff; }
    .cv-mode { padding: 7px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; color: var(--gray-600); text-decoration: none; }
    .cv-mode.on { background: var(--primary); color: #fff; }

    /* ── Grid ── */
    .cv-grid { background: #fff; border: 1px solid var(--gray-100); border-radius: 14px; overflow: hidden; box-shadow: 0 1px 2px rgba(16,24,40,.04); }
    .cv-scroll { overflow-x: auto; }
    .cv-head { display: grid; border-bottom: 1px solid var(--gray-100); }
    .cv-head > div { padding: 11px 8px; text-align: center; font-size: 12px; font-weight: 600; color: var(--gray-500); border-left: 1px solid var(--gray-100); }
    .cv-head > div:first-child { border-left: 0; }
    .cv-head .cv-dnum { display: block; font-size: 17px; font-weight: 700; color: var(--forest); margin-top: 2px; }
    .cv-head .today .cv-dnum { width: 30px; height: 30px; margin: 2px auto 0; border-radius: 50%; background: var(--mint); color: #fff; display: grid; place-items: center; }
    .cv-head a { text-decoration: none; color: inherit; display: block; }
    .cv-head .sel { background: #EAF6FC; }

    .cv-body { display: grid; position: relative; }
    .cv-hours { border-right: 1px solid var(--gray-100); }
    .cv-hour { height: 54px; padding: 2px 8px 0 0; text-align: right; font-size: 11px; color: var(--gray-400); border-top: 1px solid var(--gray-100); }
    .cv-hour:first-child { border-top: 0; }
    .cv-col { position: relative; border-left: 1px solid var(--gray-100); }
    .cv-col .cv-slot { height: 54px; border-top: 1px solid var(--gray-100); }
    .cv-col .cv-slot:first-child { border-top: 0; }

    .cv-ev {
        position: absolute; left: 4px; right: 4px; padding: 5px 7px; border-radius: 7px;
        border-left: 3px solid currentColor; font-size: 11px; line-height: 1.35;
        overflow: hidden; text-decoration: none; cursor: pointer;
    }
    .cv-ev b { display: block; font-weight: 700; color: var(--gray-800); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cv-ev span { display: block; color: var(--gray-500); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cv-ev:hover { filter: brightness(.97); }

    /* ── Month ── */
    .cv-month { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); }
    .cv-cell { min-height: 108px; padding: 7px; border-top: 1px solid var(--gray-100); border-left: 1px solid var(--gray-100); }
    .cv-cell:nth-child(7n+1) { border-left: 0; }
    .cv-cell.out { background: var(--gray-50, #FAFAFB); }
    .cv-cell.sel { background: #EAF6FC; }
    .cv-cell-d { display: inline-grid; place-items: center; width: 24px; height: 24px; border-radius: 50%; font-size: 12px; font-weight: 600; color: var(--gray-600); text-decoration: none; }
    .cv-cell.out .cv-cell-d { color: var(--gray-300); }
    .cv-cell-d.today { background: var(--mint); color: #fff; }
    .cv-chip {
        display: block; margin-top: 4px; padding: 3px 7px; border-radius: 6px;
        border-left: 3px solid currentColor; font-size: 10.5px; font-weight: 600;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-decoration: none;
    }
    .cv-more { display: block; margin-top: 4px; font-size: 10.5px; color: var(--gray-400); text-decoration: none; }

    .cv-legend { display: flex; gap: 16px; flex-wrap: wrap; padding: 12px 16px; border-top: 1px solid var(--gray-100); font-size: 12px; color: var(--gray-500); }
    .cv-legend span { display: inline-flex; align-items: center; gap: 6px; }
    .cv-legend i { width: 9px; height: 9px; border-radius: 50%; }

    /* ── Day panel ── */
    .cv-panel { flex: none; width: 360px; position: sticky; top: 0; max-height: calc(100vh - 96px); display: flex; flex-direction: column;
        background: #fff; border: 1px solid var(--gray-100); border-radius: 16px; box-shadow: 0 8px 28px -14px rgba(16,24,40,.24); }
    .cv-panel-hd { display: flex; align-items: center; gap: 12px; padding: 15px 18px; border-bottom: 1px solid var(--gray-100); }
    .cv-panel-ico { width: 40px; height: 40px; flex: none; border-radius: 11px; background: #EAF1FB; color: #1A5C9A; display: grid; place-items: center; }
    .cv-panel-ico svg { width: 19px; height: 19px; }
    .cv-panel-hd b { display: block; font-size: 15px; font-weight: 700; color: var(--forest); }
    .cv-panel-hd span { font-size: 12.5px; color: var(--gray-400); }
    .cv-panel-body { padding: 14px 16px 18px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; }
    .cv-item { padding: 13px 14px; border: 1px solid var(--gray-100); border-left: 3px solid currentColor; border-radius: 12px; }
    .cv-item-hd { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 12px; font-weight: 600; color: var(--gray-500); }
    .cv-item h4 { margin: 6px 0 2px; font-size: 14px; font-weight: 700; color: var(--forest); }
    .cv-item-t { font-size: 12px; color: var(--gray-400); }
    .cv-item-p { display: flex; align-items: center; gap: 9px; margin: 10px 0; }
    .cv-item-p .ss-av { width: 30px; height: 30px; font-size: 10.5px; }
    .cv-item-p b { font-size: 12.5px; font-weight: 600; color: var(--gray-800); display: block; }
    .cv-item-p span { font-size: 11px; color: var(--gray-400); }
    .cv-item-m { display: flex; gap: 12px; flex-wrap: wrap; font-size: 11.5px; color: var(--gray-500); margin-bottom: 10px; }
    .cv-item-m span { display: inline-flex; align-items: center; gap: 5px; }
    .cv-item-m svg { width: 13px; height: 13px; color: var(--gray-400); }
    .cv-item-a { display: flex; gap: 8px; }
    .cv-item-a a { flex: 1; text-align: center; padding: 8px 10px; border: 1px solid var(--gray-200); border-radius: 9px; font-size: 12.5px; font-weight: 600; color: var(--gray-700); text-decoration: none; }
    .cv-item-a a.primary { background: var(--mint); border-color: var(--mint); color: #fff; }
    .cv-item-a a:hover { border-color: var(--mint); }
    .cv-item-a a.primary:hover { background: #0868AD; }

    @media (max-width: 1400px) { .cv-wrap { flex-direction: column; } .cv-panel { width: 100%; position: static; max-height: none; } }
</style>

<div class="cv-wrap">
    <div class="cv-main">

        <div class="ss-hd">
            <div>
                <h1>Calendar view</h1>
                <p>Every mentorship session laid out against the clock.</p>
            </div>
        </div>

        <div class="cv-bar">
            <a class="cv-btn" href="<?= cal_url(['d' => date('Y-m-d'), 'sel' => date('Y-m-d')]) ?>"><?= ss_icon('cal') ?>Today</a>
            <div class="cv-nav">
                <a class="cv-btn sq" href="<?= cal_url(['d' => $prev, 'sel' => null]) ?>" aria-label="Previous">‹</a>
                <a class="cv-btn sq" href="<?= cal_url(['d' => $next, 'sel' => null]) ?>" aria-label="Next">›</a>
            </div>
            <span class="cv-title"><?= htmlspecialchars($heading) ?></span>
            <div class="cv-modes">
                <?php foreach (['month' => 'Month', 'week' => 'Week', 'day' => 'Day'] as $m => $ml): ?>
                    <a class="cv-mode <?= $mode === $m ? 'on' : '' ?>" href="<?= cal_url(['mode' => $m]) ?>"><?= $ml ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <form class="ss-filters" method="get" action="<?= url('admin-sessions-calendar') ?>">
            <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
            <input type="hidden" name="d" value="<?= htmlspecialchars($focusDay) ?>">
            <div class="ss-field">
                <select name="status" aria-label="Status">
                    <option value="">All statuses</option>
                    <?php foreach ($STATES as $k => [$l, , ]): ?>
                        <option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ss-field">
                <select name="type" aria-label="Type">
                    <option value="">All types</option>
                    <option value="1v1" <?= $fType === '1v1' ? 'selected' : '' ?>>1-on-1</option>
                    <option value="group" <?= $fType === 'group' ? 'selected' : '' ?>>Group</option>
                </select>
            </div>
            <div class="ss-field">
                <select name="club" aria-label="Club">
                    <option value="">Clubs</option>
                    <?php foreach ($clubs as $cl): ?>
                        <option value="<?= htmlspecialchars($cl) ?>" <?= $fClub === $cl ? 'selected' : '' ?>><?= htmlspecialchars($cl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ss-field">
                <select name="mentor" aria-label="Mentor">
                    <option value="">All mentors</option>
                    <?php foreach ($mentors as $m): ?>
                        <option value="<?= (int)$m['user_id'] ?>" <?= $fMentor === (int)$m['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['nm']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="ss-apply">Apply</button>
            <?php if ($hasFilter): ?>
                <a class="ss-clear" href="<?= url('admin-sessions-calendar') . '?mode=' . $mode . '&d=' . $focusDay ?>">Clear filters</a>
            <?php endif; ?>
        </form>

        <div class="cv-grid">
            <?php if ($mode === 'month'): ?>
                <div class="cv-head" style="grid-template-columns:repeat(7,minmax(0,1fr));">
                    <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $d): ?><div><?= $d ?></div><?php endforeach; ?>
                </div>
                <div class="cv-month">
                    <?php
                    $cur = strtotime($rangeStart);
                    $endTs = strtotime($rangeEnd);
                    $month = date('m', $focusTs);
                    while ($cur <= $endTs):
                        $d = date('Y-m-d', $cur);
                        $items = $byDay[$d] ?? [];
                    ?>
                        <div class="cv-cell<?= date('m', $cur) !== $month ? ' out' : '' ?><?= $d === $selected ? ' sel' : '' ?>">
                            <a class="cv-cell-d<?= $d === date('Y-m-d') ? ' today' : '' ?>" href="<?= cal_url(['sel' => $d]) ?>"><?= (int)date('j', $cur) ?></a>
                            <?php foreach (array_slice($items, 0, 3) as $s):
                                $st = ad_session_state($s);
                                [$l, $fg, $bg] = $STATES[$st]; ?>
                                <a class="cv-chip" href="<?= cal_url(['sel' => $d]) ?>" style="color:<?= $fg ?>;background:<?= $bg ?>;"
                                   title="<?= htmlspecialchars(date('g:i A', strtotime($s['session_date'])) . ' · ' . $s['subject'] . ' · ' . $l) ?>">
                                    <?= date('g:i A', strtotime($s['session_date'])) ?> <?= htmlspecialchars($s['subject'] ?: 'Session') ?>
                                </a>
                            <?php endforeach; ?>
                            <?php if (count($items) > 3): ?>
                                <a class="cv-more" href="<?= cal_url(['sel' => $d]) ?>">+<?= count($items) - 3 ?> more</a>
                            <?php endif; ?>
                        </div>
                    <?php $cur = strtotime('+1 day', $cur); endwhile; ?>
                </div>

            <?php else:
                $days = [];
                $cur = strtotime($rangeStart);
                while ($cur <= strtotime($rangeEnd)) { $days[] = date('Y-m-d', $cur); $cur = strtotime('+1 day', $cur); }
                $cols = 'grid-template-columns:64px repeat(' . count($days) . ', minmax(120px, 1fr));';
            ?>
                <div class="cv-scroll">
                    <div class="cv-head" style="<?= $cols ?>">
                        <div></div>
                        <?php foreach ($days as $d): ?>
                            <div class="<?= $d === date('Y-m-d') ? 'today' : '' ?><?= $d === $selected ? ' sel' : '' ?>">
                                <a href="<?= cal_url(['sel' => $d]) ?>">
                                    <?= date('D', strtotime($d)) ?>
                                    <span class="cv-dnum"><?= (int)date('j', strtotime($d)) ?></span>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="cv-body" style="<?= $cols ?>">
                        <div class="cv-hours">
                            <?php foreach ($hours as $h): ?>
                                <div class="cv-hour"><?= date('g A', mktime($h, 0, 0)) ?></div>
                            <?php endforeach; ?>
                        </div>
                        <?php foreach ($days as $d): ?>
                            <div class="cv-col">
                                <?php foreach ($hours as $h): ?><div class="cv-slot"></div><?php endforeach; ?>
                                <?php foreach (($byDay[$d] ?? []) as $s):
                                    $st = ad_session_state($s);
                                    [$l, $fg, $bg] = $STATES[$st];
                                    $ts   = strtotime($s['session_date']);
                                    $mins = ad_session_minutes($s);
                                    // 54px to the hour; a short session still needs to be readable.
                                    $top  = ((int)date('G', $ts) - $minH) * 54 + ((int)date('i', $ts) / 60) * 54;
                                    $hgt  = max(34, $mins / 60 * 54);
                                    if ($top < 0) continue;
                                ?>
                                    <a class="cv-ev" href="<?= cal_url(['sel' => $d]) ?>"
                                       style="top:<?= round($top) ?>px;height:<?= round($hgt) ?>px;color:<?= $fg ?>;background:<?= $bg ?>;"
                                       title="<?= htmlspecialchars($s['subject'] . ' · ' . ad_time_range($s) . ' · ' . $l) ?>">
                                        <b><?= date('g:i A', $ts) ?></b>
                                        <span><?= htmlspecialchars($s['subject'] ?: 'Session') ?></span>
                                        <span><?= htmlspecialchars($s['mentor_name']) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="cv-legend">
                <?php foreach ($STATES as $k => [$l, $fg, $bg]): ?>
                    <span><i style="background:<?= $fg ?>"></i><?= $l ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ══════════ The selected day ══════════ -->
    <aside class="cv-panel">
        <div class="cv-panel-hd">
            <span class="cv-panel-ico"><?= ss_icon('cal') ?></span>
            <div>
                <b>Sessions on <?= date('F j, Y', strtotime($selected)) ?></b>
                <span><?= count($dayList) ?> session<?= count($dayList) === 1 ? '' : 's' ?> scheduled</span>
            </div>
        </div>
        <div class="cv-panel-body">
            <?php if (!$dayList): ?>
                <p class="ss-none">Nothing is scheduled for this day<?= $hasFilter ? ' under these filters' : '' ?>.</p>
            <?php else: foreach ($dayList as $s):
                $st = ad_session_state($s);
                [$l, $fg, $bg] = $STATES[$st];
                $id = (int)$s['request_id'];
                $isGroup = ($s['session_type'] ?? '') === 'group';
                $seats = $isGroup ? AdminSessionRepository::groupSize($con, $s) : 1;
            ?>
                <div class="cv-item" style="color:<?= $fg ?>;">
                    <div class="cv-item-hd">
                        <span style="color:var(--gray-500);"><?= ad_time_range($s) ?></span>
                        <span class="ss-pill" style="color:<?= $fg ?>;background:<?= $bg ?>;"><?= $l ?></span>
                    </div>
                    <h4><?= htmlspecialchars($s['subject'] ?: 'Mentoring session') ?></h4>
                    <div class="cv-item-t"><?= $isGroup ? 'Group session · ' . $seats . ' mentee' . ($seats === 1 ? '' : 's') : ($s['session_type'] === '1v1' ? '1-on-1 session' : 'Session') ?></div>

                    <div class="cv-item-p">
                        <span class="ss-av"><?= $s['mentor_pic'] ? '<img src="' . htmlspecialchars($s['mentor_pic']) . '" alt="">' : htmlspecialchars(strtoupper(substr($s['mentor_name'], 0, 2))) ?></span>
                        <span style="min-width:0;"><b><?= htmlspecialchars($s['mentor_name']) ?></b><span>Mentor</span></span>
                        <span style="color:var(--gray-300);"><?= ss_icon('swap') ?></span>
                        <span class="ss-av"><?= $s['mentee_pic'] ? '<img src="' . htmlspecialchars($s['mentee_pic']) . '" alt="">' : htmlspecialchars(strtoupper(substr($s['mentee_name'], 0, 2))) ?></span>
                        <span style="min-width:0;"><b><?= htmlspecialchars($s['mentee_name']) ?></b><span>Mentee</span></span>
                    </div>

                    <div class="cv-item-m">
                        <span><?= ss_icon('clock') ?><?= ad_duration_label(ad_session_minutes($s)) ?></span>
                        <span style="color:var(--gray-400);">#<?= ad_session_ref($id, $s['session_date']) ?></span>
                    </div>

                    <div class="cv-item-a">
                        <a class="primary" href="<?= url('admin-sessions') ?>?open=<?= $id ?>#panel">View details</a>
                        <a href="<?= url('admin-user') ?>?id=<?= (int)$s['mentor_id'] ?>">Mentor</a>
                        <a href="<?= url('admin-user') ?>?id=<?= (int)$s['mentee_id'] ?>">Mentee</a>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </aside>
</div>

<?php include __DIR__ . '/layout_end.php'; ?>
