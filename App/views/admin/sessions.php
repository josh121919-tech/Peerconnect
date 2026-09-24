<?php

/**
 * admin/sessions.php — All Sessions.
 *
 * Every mentoring session on the platform, with the detail panel an admin
 * needs to settle one: who was in it, when, how it ended, what they said
 * about each other afterwards, and the one thing an admin can do about it:
 * call it off.
 *
 * An admin cannot close a session as completed or missed. The mentor ending
 * the call or the mentee leaving feedback completes it; otherwise the
 * missed-session job closes it an hour after it ends, from who joined the
 * call. The page says so on each open session, and warns when sessions have
 * gone unclosed for longer than that job allows, which means it is not
 * running.
 *
 * Figures are live counts of rows that exist. The reference design showed a
 * "12.5% from last month" style trend on each tile; session_requests has no
 * created_at, so there is no honest way to say how many were *booked* last
 * month — the trends here compare sessions *scheduled* in each month, which
 * is what the dates in the table can actually support, and they are labelled
 * as that. Controls the data cannot back (a recording link, a flag) are not
 * drawn at all rather than drawn dead.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/session_data.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$STATES = ad_session_states();

/* ── Filters ──────────────────────────────────────────────────────────── */
$VIEWS = ['all', 'pending', 'upcoming', 'ongoing', 'overdue', 'completed', 'cancelled', 'declined', 'missed'];
$view  = in_array($_GET['tab'] ?? '', $VIEWS, true) ? $_GET['tab'] : 'all';

$q       = trim(ad_query('q'));
$type    = in_array($_GET['type'] ?? '', ['1v1', 'group'], true) ? $_GET['type'] : '';
$subject = trim(ad_query('subject'));
$from    = trim(ad_query('from'));
$to      = trim(ad_query('to'));
$sort    = in_array($_GET['sort'] ?? '', ['newest', 'oldest', 'subject'], true) ? $_GET['sort'] : 'newest';
$open    = (int)($_GET['open'] ?? 0);

$perPage = 8;
$page    = max(1, (int)($_GET['page'] ?? 1));

/* ── The filters every query below shares ─────────────────────────────── */
$filters = [
    'q'       => $q,
    'subject' => $subject,
    'type'    => $type,
    'from'    => ($from !== '' && strtotime($from)) ? date('Y-m-d', strtotime($from)) : '',
    'to'      => ($to !== '' && strtotime($to)) ? date('Y-m-d', strtotime($to)) : '',
];

/* ── Tab counts, under the same filters ───────────────────────────────── */
$counts = AdminSessionRepository::tabCounts($con, $filters);

/* ── Headline figures ─────────────────────────────────────────────────── */
$figures = AdminSessionRepository::headlineFigures($con);

$totalAll    = $figures['total'];
$doneAll     = $figures['completed'];
$cancelAll   = $figures['cancelled'];
$upcomingAll = $figures['upcoming'];
$todayCount  = $figures['today'];

// The missed-session job closes a session PC_MISSED_GRACE_MINUTES after it
// ends and runs every 30 minutes. One still open 90 minutes past that —
// three runs later — means the job has stopped.
$staleAfter = PC_MISSED_GRACE_MINUTES + 90;
$unclosed   = AdminSessionRepository::countUnclosedOlderThan($con, $staleAfter);

// "vs last month" here means sessions SCHEDULED in each month — the only
// month-over-month comparison the columns support.
function ad_trend(int $now, int $prev): ?array
{
    if ($prev <= 0) return null;
    $pct = (int)round((($now - $prev) / $prev) * 100);
    return $pct === 0 ? null : ['up' => $pct > 0, 'label' => ($pct > 0 ? '+' : '') . $pct . '% vs last month'];
}
$trTotal = ad_trend($figures['month_total'], $figures['prev_total']);
$trDone  = ad_trend($figures['month_completed'], $figures['prev_completed']);
$trCanc  = ad_trend($figures['month_cancelled'], $figures['prev_cancelled']);

// A completion rate over sessions that have actually concluded — counting
// sessions still in the future as "not completed" would understate it.
$concluded  = $doneAll + $cancelAll + $figures['missed'];
$completion = $concluded > 0 ? round($doneAll / $concluded * 100, 1) : null;

/* ── The list ─────────────────────────────────────────────────────────── */
$total      = AdminSessionRepository::countMatching($con, $filters, $view);
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$rows = AdminSessionRepository::page($con, $filters, $view, $sort, $perPage, $offset);

/* ── Subjects, for the filter ─────────────────────────────────────────── */
$subjects = AdminSessionRepository::subjects($con);

/* ── The session in the side panel ────────────────────────────────────── */
$detail = null;
$detailFeedback = [];
$detailReview = null;
$detailGroup = 1;
if ($open > 0) {
    $detail = AdminSessionRepository::find($con, $open);

    if ($detail) {
        $detailGroup = AdminSessionRepository::groupSize($con, $detail);

        // What the mentee said about the mentor, and what the mentor said
        // about the mentee, for this session.
        $detailFeedback = FeedbackRepository::forSession($con, $open);
        $detailReview   = FeedbackRepository::menteeReviewForSession($con, $open);
    }
}

/** Keep the filters when changing one thing. */
function ss_url(array $over = []): string
{
    $p = array_merge([
        'tab' => $_GET['tab'] ?? null, 'q' => $_GET['q'] ?? null, 'type' => $_GET['type'] ?? null,
        'subject' => $_GET['subject'] ?? null, 'from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null,
        'sort' => $_GET['sort'] ?? null, 'page' => $_GET['page'] ?? null, 'open' => $_GET['open'] ?? null,
    ], $over);
    $p = array_filter($p, fn($v) => $v !== null && $v !== '');
    return url('admin-sessions') . ($p ? '?' . http_build_query($p) : '');
}

$hasFilter = ($q !== '' || $type !== '' || $subject !== '' || $from !== '' || $to !== '');
$csrf = csrf_token();
$backHere = ss_url();

$current_page = 'sessions';
include 'layout.php';
include __DIR__ . '/includes/sessions_ui.php';
?>

<div class="ss-wrap<?= $detail ? ' has-panel' : '' ?>">
    <div class="ss-main">

        <div class="ss-hd">
            <div>
                <h1>Sessions</h1>
                <p>Monitor mentorship sessions, schedules, attendance and session activity.</p>
            </div>
            <div class="ss-hd-actions">
                <?php // Exports exactly what the current filters select, so the file
                //     matches the screen it was taken from. ?>
                <?php // Built once: the CSV and the PDF must export the same selection. ?>
                <?php $ss_export_qs = http_build_query(array_filter([
                    'tab' => $view !== 'all' ? $view : null, 'q' => $q ?: null, 'type' => $type ?: null,
                    'subject' => $subject ?: null, 'from' => $from ?: null, 'to' => $to ?: null,
                ], fn($v) => $v !== null)); ?>
                <a class="ss-export" href="<?= url('admin-sessions-export') . '?' . $ss_export_qs ?>">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0 4-4m-4 4-4-4M4 19h16" /></svg>
                    Export <?= $total ?> row<?= $total === 1 ? '' : 's' ?>
                </a>
                <a class="ss-export is-pdf" href="<?= url('admin-sessions-export') . '?' . $ss_export_qs ?>&amp;format=pdf">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V3h12v6M6 18H4v-6h16v6h-2M8 14h8v7H8v-7Z" /></svg>
                    Export PDF
                </a>
            </div>
        </div>

        <?php if ($unclosed > 0): ?>
            <div class="ss-stale" role="alert">
                <div class="ss-stale-txt">
                    <b><?= $unclosed ?> session<?= $unclosed === 1 ? ' has' : 's have' ?> not been closed</b>
                    <span>
                        <?= $unclosed === 1 ? 'It' : 'They' ?> ended more than <?= ad_duration_label($staleAfter) ?> ago.
                        Sessions are closed automatically every 30 minutes by the PeerConnect Maintenance
                        task in Windows Task Scheduler, so it may have stopped running.
                        <a href="<?= ss_url(['tab' => 'overdue', 'page' => null, 'open' => null]) ?>">Show them</a>
                    </span>
                </div>
                <form method="post" action="<?= url('cron-missed-sessions') ?>"
                      data-pc-tone="warning" data-pc-ok="Run the check"
                      data-pc-confirm="Close every approved session that ended more than <?= pc_missed_grace_label() ?> ago?&#10;If both people joined the call it is marked completed. Otherwise it is recorded as missed by whoever did not join, and both people are told. Requests the mentor never answered are removed once their time has passed. This is the same check the task runs.">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="back" value="<?= htmlspecialchars($backHere) ?>">
                    <button type="submit" class="ss-act warn"><?= ss_icon('clock') ?>Run the check now</button>
                </form>
            </div>
        <?php endif; ?>

        <!-- ══════════ Figures ══════════ -->
        <div class="ss-stats">
            <?php
            $tiles = [
                ['Total sessions', number_format($totalAll), $trTotal, 'All time', '#EAF1FB', '#1A5C9A', 'cal'],
                ['Upcoming', number_format($upcomingAll), null, $todayCount . ' scheduled today', '#E6F5EE', '#17654B', 'clock'],
                ['Completed', number_format($doneAll), $trDone, $completion !== null ? $completion . '% of concluded sessions' : 'None concluded yet', '#EAF6FC', '#087FC1', 'check'],
                ['Cancelled or declined', number_format($cancelAll), $trCanc, 'Includes declined requests', '#FBE5E1', '#A6301F', 'x'],
            ];
            foreach ($tiles as [$label, $value, $trend, $sub, $bg, $fg, $ico]): ?>
                <div class="ss-stat">
                    <span class="ss-stat-ico" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= ss_icon($ico) ?></span>
                    <div style="min-width:0;">
                        <div class="ss-stat-k"><?= $label ?></div>
                        <div class="ss-stat-v"><?= $value ?></div>
                        <div class="ss-stat-s">
                            <?php if ($trend): ?>
                                <span class="ss-trend <?= $trend['up'] ? 'up' : 'down' ?>"><?= $trend['up'] ? '↑' : '↓' ?> <?= htmlspecialchars($trend['label']) ?></span>
                            <?php else: ?>
                                <?= htmlspecialchars($sub) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ══════════ Tabs ══════════ -->
        <div class="ss-tabs">
            <?php foreach ([
                ['all', 'All', $counts['all_c']],
                ['pending', 'Pending', $counts['pending']],
                ['upcoming', 'Upcoming', $counts['upcoming']],
                ['ongoing', 'Ongoing', $counts['ongoing']],
                ['overdue', 'Not closed', $counts['overdue']],
                ['completed', 'Completed', $counts['completed']],
                ['cancelled', 'Cancelled', $counts['cancelled']],
                ['declined', 'Declined', $counts['declined']],
                ['missed', 'Missed', $counts['missed']],
            ] as [$k, $label, $n]): ?>
                <a class="ss-tab <?= $view === $k ? 'on' : '' ?>" href="<?= ss_url(['tab' => $k === 'all' ? null : $k, 'page' => null, 'open' => null]) ?>">
                    <?= $label ?><span class="ss-tab-n"><?= (int)$n ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- ══════════ Filters ══════════ -->
        <form class="ss-filters" method="get" action="<?= url('admin-sessions') ?>">
            <?php if ($view !== 'all'): ?><input type="hidden" name="tab" value="<?= htmlspecialchars($view) ?>"><?php endif; ?>
            <div class="ss-field ss-grow">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="m20 20-4-4" /></svg>
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search mentor, mentee, subject or session ID…">
            </div>
            <div class="ss-field"><label for="f-from">From</label><input id="f-from" type="date" name="from" value="<?= htmlspecialchars($from) ?>"></div>
            <div class="ss-field"><label for="f-to">To</label><input id="f-to" type="date" name="to" value="<?= htmlspecialchars($to) ?>"></div>
            <div class="ss-field">
                <select name="type" aria-label="Session type">
                    <option value="">Any type</option>
                    <option value="1v1" <?= $type === '1v1' ? 'selected' : '' ?>>1-on-1</option>
                    <option value="group" <?= $type === 'group' ? 'selected' : '' ?>>Group</option>
                </select>
            </div>
            <div class="ss-field">
                <select name="subject" aria-label="Subject">
                    <option value="">All subjects</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>" <?= $subject === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ss-field">
                <select name="sort" aria-label="Sort">
                    <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
                    <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
                    <option value="subject" <?= $sort === 'subject' ? 'selected' : '' ?>>By subject</option>
                </select>
            </div>
            <button type="submit" class="ss-apply">Apply</button>
            <?php if ($hasFilter): ?>
                <a class="ss-clear" href="<?= url('admin-sessions') . ($view !== 'all' ? '?tab=' . $view : '') ?>">Clear filters</a>
            <?php endif; ?>
        </form>

        <!-- ══════════ List ══════════ -->
        <?php if (!$rows): ?>
            <div class="ss-empty">
                <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="15" rx="2.5" /><path stroke-linecap="round" d="M8 3v4M16 3v4M3.5 10h17" /></svg>
                <p><?= $hasFilter || $view !== 'all' ? 'No sessions match this view.' : 'No sessions have been booked yet.' ?></p>
            </div>
        <?php else: ?>
            <div class="ss-list">
                <?php foreach ($rows as $s):
                    $state = ad_session_state($s);
                    [$sl, $sfg, $sbg] = $STATES[$state];
                    $mins = ad_session_minutes($s);
                    $isGroup = ($s['session_type'] ?? '') === 'group';
                    $seats = $isGroup ? AdminSessionRepository::groupSize($con, $s) : 1;
                    $id = (int)$s['request_id'];
                ?>
                    <div class="ss-row<?= $open === $id ? ' on' : '' ?>">
                        <div class="ss-date">
                            <span><?= strtoupper(date('M', strtotime($s['session_date']))) ?></span>
                            <b><?= date('d', strtotime($s['session_date'])) ?></b>
                            <span><?= date('Y', strtotime($s['session_date'])) ?></span>
                        </div>

                        <div class="ss-what">
                            <div class="ss-title"><?= htmlspecialchars($s['subject'] ?: 'Mentoring session') ?></div>
                            <div class="ss-ref">#<?= ad_session_ref($id, $s['session_date']) ?></div>
                            <?php if (trim((string)$s['topics']) !== ''): ?>
                                <div class="ss-tags">
                                    <?php foreach (array_slice(array_filter(array_map('trim', explode(',', $s['topics']))), 0, 3) as $t): ?>
                                        <span class="ss-tag"><?= htmlspecialchars($t) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="ss-meta">
                                <span><?= ss_icon($isGroup ? 'group' : 'one') ?><?= $isGroup ? 'Group (' . $seats . ' mentee' . ($seats === 1 ? '' : 's') . ')' : '1-on-1' ?></span>
                            </div>
                        </div>

                        <div class="ss-people">
                            <?= ss_person($s['mentor_pic'], $s['mentor_name'], 'Mentor', $s['mentor_rating']) ?>
                            <span class="ss-swap"><?= ss_icon('swap') ?></span>
                            <?= ss_person($s['mentee_pic'], $s['mentee_name'], 'Mentee', $s['mentee_rating']) ?>
                        </div>

                        <div class="ss-when">
                            <span><?= ss_icon('cal') ?><?= date('M j, Y', strtotime($s['session_date'])) ?></span>
                            <span><?= ss_icon('clock') ?><?= ad_time_range($s) ?></span>
                            <span class="ss-dur"><?= ad_duration_label($mins) ?></span>
                        </div>

                        <div class="ss-end">
                            <span class="ss-pill" style="color:<?= $sfg ?>;background:<?= $sbg ?>;"><?= $sl ?></span>
                            <a class="ss-view" href="<?= ss_url(['open' => $id]) ?>#panel">View session</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="ss-foot">
                <span>Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= number_format($total) ?> session<?= $total === 1 ? '' : 's' ?></span>
                <?php pc_pagination($page, $totalPages, fn(int $n) => ss_url(['page' => $n]), ['label' => 'Session pages']); ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ══════════ Detail panel ══════════ -->
    <?php if ($detail):
        $state = ad_session_state($detail);
        [$sl, $sfg, $sbg] = $STATES[$state];
        $mins = ad_session_minutes($detail);
        $did  = (int)$detail['request_id'];
        $isGroup = ($detail['session_type'] ?? '') === 'group';
    ?>
        <aside class="ss-panel" id="panel">
            <div class="ss-panel-hd">
                <b>Session details</b>
                <a class="ss-x" href="<?= ss_url(['open' => null]) ?>" aria-label="Close">&times;</a>
            </div>

            <div class="ss-panel-body">
                <div class="ss-panel-top">
                    <span class="ss-pill" style="color:<?= $sfg ?>;background:<?= $sbg ?>;"><?= $sl ?></span>
                    <span class="ss-ref">#<?= ad_session_ref($did, $detail['session_date']) ?></span>
                </div>
                <h2 class="ss-panel-title"><?= htmlspecialchars($detail['subject'] ?: 'Mentoring session') ?></h2>
                <?php if (trim((string)$detail['topics']) !== ''): ?>
                    <p class="ss-panel-sub"><?= htmlspecialchars($detail['topics']) ?></p>
                <?php endif; ?>

                <div class="ss-kv">
                    <div><span class="ss-k">Date</span><span class="ss-v"><?= date('M j, Y', strtotime($detail['session_date'])) ?></span></div>
                    <div><span class="ss-k">Time</span><span class="ss-v"><?= ad_time_range($detail) ?></span></div>
                    <div><span class="ss-k">Duration</span><span class="ss-v"><?= ad_duration_label($mins) ?></span></div>
                    <div><span class="ss-k">Type</span><span class="ss-v"><?= $isGroup ? 'Group session' : ($detail['session_type'] === '1v1' ? '1-on-1 session' : 'Not recorded') ?></span></div>
                    <?php if ($isGroup): ?>
                        <div><span class="ss-k">Seats taken</span><span class="ss-v"><?= $detailGroup ?><?= $detail['capacity'] ? ' of ' . (int)$detail['capacity'] : '' ?></span></div>
                    <?php endif; ?>
                    <?php if ($detail['completed_at']): ?>
                        <div><span class="ss-k">Closed</span><span class="ss-v"><?= date('M j, Y g:i A', strtotime($detail['completed_at'])) ?></span></div>
                    <?php endif; ?>
                    <?php if ($detail['missed_by'] && $detail['missed_by'] !== 'none'): ?>
                        <div><span class="ss-k">Missed by</span><span class="ss-v"><?= htmlspecialchars(ucfirst($detail['missed_by'])) ?></span></div>
                    <?php endif; ?>
                </div>

                <?php if (trim((string)$detail['rejection_reason']) !== ''): ?>
                    <div class="ss-note">
                        <b><?= $detail['status'] === 'rejected' ? 'Declined because' : 'Cancelled because' ?></b>
                        <?= nl2br(htmlspecialchars($detail['rejection_reason'])) ?>
                    </div>
                <?php endif; ?>

                <?php if (trim((string)$detail['message']) !== ''): ?>
                    <div class="ss-note">
                        <b>What the mentee asked for</b>
                        <?= nl2br(htmlspecialchars($detail['message'])) ?>
                    </div>
                <?php endif; ?>

                <h3 class="ss-h3">Participants</h3>
                <div class="ss-parts">
                    <?php foreach ([
                        ['mentor', $detail['mentor_id'], $detail['mentor_name'], $detail['mentor_pic'], $detail['mentor_course'], $detail['mentor_rating']],
                        ['mentee', $detail['mentee_id'], $detail['mentee_name'], $detail['mentee_pic'], $detail['mentee_course'], $detail['mentee_rating']],
                    ] as [$role, $uid, $nm, $pic, $course, $rating]): ?>
                        <a class="ss-part" href="<?= url('admin-user') ?>?id=<?= (int)$uid ?>">
                            <span class="ss-av"><?= $pic ? '<img src="' . htmlspecialchars($pic) . '" alt="">' : htmlspecialchars(strtoupper(substr($nm, 0, 2))) ?></span>
                            <span style="min-width:0;">
                                <b><?= htmlspecialchars($nm) ?></b>
                                <span class="ss-part-r"><?= ucfirst($role) ?></span>
                                <?php if ($rating !== null): ?><span class="ss-part-s">★ <?= number_format((float)$rating, 1) ?></span><?php endif; ?>
                                <?php if (trim((string)$course) !== ''): ?><span class="ss-part-c"><?= htmlspecialchars($course) ?></span><?php endif; ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <h3 class="ss-h3">Feedback</h3>
                <?php if (!$detailFeedback && !$detailReview): ?>
                    <p class="ss-none"><?= $state === 'completed' ? 'Neither person has left feedback for this session.' : 'Feedback opens once the session is completed.' ?></p>
                <?php else: ?>
                    <?php
                    $cards = [];
                    if ($detailFeedback) $cards[] = ['The mentee rated the mentor', $detail['mentee_name'], $detailFeedback,
                        ['communication' => 'Communication', 'knowledge' => 'Knowledge', 'efficiency' => 'Efficiency', 'skill' => 'Skill']];
                    if ($detailReview) $cards[] = ['The mentor rated the mentee', $detail['mentor_name'], $detailReview,
                        ['preparedness' => 'Preparedness', 'participation' => 'Participation', 'communication' => 'Communication', 'receptiveness' => 'Receptiveness']];
                    foreach ($cards as [$heading, $author, $f, $axes]): ?>
                        <div class="ss-fb">
                            <div class="ss-fb-hd">
                                <span><?= $heading ?></span>
                                <span class="ss-fb-when"><?= $f['created_at'] ? date('M j, Y', strtotime($f['created_at'])) : '' ?></span>
                            </div>
                            <div class="ss-fb-score">
                                <b><?= number_format((float)$f['rating'], 1) ?></b> / 5
                                <span class="ss-stars"><?= str_repeat('★', (int)round((float)$f['rating'])) . str_repeat('☆', max(0, 5 - (int)round((float)$f['rating']))) ?></span>
                            </div>
                            <?php if (trim((string)($f['comment'] ?? '')) !== ''): ?>
                                <p class="ss-fb-txt">“<?= htmlspecialchars($f['comment']) ?>”</p>
                            <?php endif; ?>
                            <?php foreach ($axes as $col => $label): if (($f[$col] ?? null) === null) continue; ?>
                                <div class="ss-bar">
                                    <span><?= $label ?></span>
                                    <span class="ss-bar-t"><i style="width:<?= max(0, min(100, (int)$f[$col] * 20)) ?>%"></i></span>
                                    <span class="ss-bar-n"><?= (int)$f[$col] ?>/5</span>
                                </div>
                            <?php endforeach; ?>
                            <div class="ss-fb-by">Left by <?= htmlspecialchars($author) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <h3 class="ss-h3">Admin actions</h3>
                <?php
                // Only an open session — pending or accepted — can be called
                // off. A missed one is already settled; cancelling it would
                // erase who did not turn up.
                $canCancel = in_array($detail['status'], ['pending', 'approved'], true);
                $endTs     = strtotime($detail['session_date']) + $mins * 60;
                ?>
                <?php if ($detail['status'] === 'approved'): ?>
                    <p class="ss-note">
                        <b>How it closes</b>
                        <?php if ($state === 'overdue'): ?>
                            It ended<?= date('Y-m-d', $endTs) !== date('Y-m-d') ? ' on ' . date('M j', $endTs) : '' ?> at <?= date('g:i A', $endTs) ?> and has not been closed yet.
                        <?php else: ?>
                            It closes on its own once it ends.
                        <?php endif; ?>
                        The mentor ending the call or the mentee leaving feedback marks it completed;
                        otherwise, about <?= pc_missed_grace_label() ?> after the end,
                        it is marked completed if both people joined the call, or missed by whoever did not.
                        <?php if ($endTs < time() - $staleAfter * 60): ?>
                            That should have happened by now — see the warning on the list.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <div class="ss-acts">
                    <?php if ($canCancel): ?>
                        <button type="button" class="ss-act danger" onclick="ssOpen('ssCancel')"><?= ss_icon('x') ?>Cancel session</button>
                    <?php endif; ?>

                    <a class="ss-act" href="<?= url('messages') ?>?chat=<?= (int)$detail['mentor_id'] ?>"><?= ss_icon('chat') ?>Message mentor</a>
                    <a class="ss-act" href="<?= url('messages') ?>?chat=<?= (int)$detail['mentee_id'] ?>"><?= ss_icon('chat') ?>Message mentee</a>
                </div>
                <?php if (!$canCancel): ?>
                    <p class="ss-none">This session is closed, so there is nothing left to change.</p>
                <?php endif; ?>
            </div>
        </aside>

        <?php /* A dialog is only drawn when its button is, so there is never a
               form on the page that nothing can open and the handler would
               refuse anyway. */ ?>
        <?php if ($canCancel): ?>
        <div class="ss-overlay" id="ssCancel">
            <form class="ss-modal" method="post" action="<?= url('admin-action-session') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="request_id" value="<?= $did ?>">
                <input type="hidden" name="back" value="<?= htmlspecialchars($backHere) ?>">
                <input type="hidden" name="action" value="cancel">
                <h3>Cancel this session?</h3>
                <p>Both the mentor and the mentee are told, along with your reason.</p>
                <label for="ssCancelReason">Reason</label>
                <textarea id="ssCancelReason" name="reason" rows="3" required placeholder="e.g. The library room is closed that afternoon."></textarea>
                <div class="ss-modal-foot">
                    <button type="button" class="ss-cancel" onclick="ssClose()">Keep it</button>
                    <button type="submit" class="ss-act danger solid">Cancel session</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
    function ssOpen(id) {
        document.querySelectorAll('.ss-overlay.open').forEach(o => o.classList.remove('open'));
        document.getElementById(id).classList.add('open');
    }
    function ssClose() {
        document.querySelectorAll('.ss-overlay.open').forEach(o => o.classList.remove('open'));
    }
    document.querySelectorAll('.ss-overlay').forEach(o => {
        o.addEventListener('click', e => { if (e.target === o) ssClose(); });
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') ssClose(); });
</script>

<?php include __DIR__ . '/layout_end.php'; ?>
