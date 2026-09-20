<?php

/**
 * admin/assessments.php — All Assessments.
 *
 * Monitoring, not authoring. Every assessment in this app is written by a
 * mentor for their own mentees, so this screen lists what mentors have made
 * and how it is going — there is no Create Assessment button here, because an
 * admin has no mentees to set work for and no such flow exists.
 *
 * The reference design also showed an "Overdue" tile and assessment types
 * like "Session Feedback" and "Program Evaluation". Neither exists here: an
 * assessment has no due date to be overdue against, and its only category is
 * the mentor's own free-text topic. Both are replaced by figures the data can
 * actually support rather than invented.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/assessment_data.php';
require_admin();

date_default_timezone_set('Asia/Manila');

/* ── Filters ──────────────────────────────────────────────────────────── */
$VIEWS = ['all', 'published', 'draft', 'attempted', 'untouched'];
$view  = in_array($_GET['tab'] ?? '', $VIEWS, true) ? $_GET['tab'] : 'all';

$str = fn(string $k) => is_scalar($_GET[$k] ?? null) ? trim((string)$_GET[$k]) : '';
$q      = $str('q');
$topic  = $str('topic');
$mentor = (int)$str('mentor');
$sort   = in_array($str('sort'), ['newest', 'oldest', 'attempts', 'score', 'title'], true) ? $str('sort') : 'newest';
$open   = (int)$str('open');

$perPage = 8;
$page    = max(1, (int)$str('page'));

$filters = ['q' => $q, 'topic' => $topic, 'mentor' => $mentor];

/* ── Tab counts, under the same filters ───────────────────────────────── */
$counts = AssessmentAdminRepository::listTabCounts($con, $filters);

/* ── Headline figures, across everything ──────────────────────────────── */
$figures    = AssessmentAdminRepository::headline($con);
$totalAll   = $figures['assessments'];
$pubAll     = $figures['published'];
$draftAll   = $figures['draft'];
$attSub     = $figures['submitted'];
$attOpen    = $figures['in_progress'];
$attTotal   = $attSub + $attOpen;
$menteesAll = $figures['mentees'];
$mentorsAll = count(AssessmentAdminRepository::mentors($con));

// Averaged per attempt as a percentage of that attempt's own total.
$avgAll = AssessmentAdminRepository::attemptFigures($con, null, null);
$avgPct = $avgAll['avg_pct'] !== null ? round((float)$avgAll['avg_pct']) : null;

// Started and then abandoned — the closest honest thing to the reference's
// "overdue", and something an admin would actually want to chase.
$finishRate = $attTotal > 0 ? round($attSub / $attTotal * 100) : null;

/* ── The list ─────────────────────────────────────────────────────────── */
$total      = AssessmentAdminRepository::countMatching($con, $filters, $view);
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$rows = AssessmentAdminRepository::page($con, $filters, $view, $sort, $perPage, $offset);

/* ── Filter options ───────────────────────────────────────────────────── */
$topics     = AssessmentAdminRepository::topics($con);
$mentorList = AssessmentAdminRepository::mentors($con);

/* ── Topic breakdown for the rail ─────────────────────────────────────── */
$byTopic = AssessmentAdminRepository::byTopic($con, 6);

/* ── Recent activity ──────────────────────────────────────────────────── */
$recent = AssessmentAdminRepository::recentAttempts($con, 6);

/* ── The assessment in the panel ──────────────────────────────────────── */
$detail = null;
$detailQuestions = [];
$detailAttempts = [];
if ($open > 0) {
    $detail = AssessmentAdminRepository::detail($con, $open);
    if ($detail) {
        $detailQuestions = AssessmentRepository::questionsWithOptions($con, $open);
        // Every attempt, newest activity first: an assessment can be taken
        // again, so one mentee may appear more than once.
        $detailAttempts = AssessmentAdminRepository::attemptPage($con, ['assessment' => $open], 200, 0);
    }
}

/** This page's address with one filter changed. */
function ab_url(array $over = []): string
{
    $p = array_merge([
        'tab' => $_GET['tab'] ?? null, 'q' => $_GET['q'] ?? null, 'topic' => $_GET['topic'] ?? null,
        'mentor' => $_GET['mentor'] ?? null, 'sort' => $_GET['sort'] ?? null,
        'page' => $_GET['page'] ?? null, 'open' => $_GET['open'] ?? null,
    ], $over);
    $p = array_filter($p, fn($v) => $v !== null && $v !== '' && !is_array($v));
    return url('admin-assessments') . ($p ? '?' . http_build_query($p) : '');
}

$hasFilter = ($q !== '' || $topic !== '' || $mentor > 0);

$csrf = csrf_token();
$backHere = ab_url();

$current_page = 'assessments';
include 'layout.php';
include __DIR__ . '/includes/assessments_ui.php';
?>

<div class="ss-wrap<?= $detail ? ' has-panel' : '' ?>">
    <div class="ss-main">

        <div class="ss-hd">
            <div>
                <h1>Assessments</h1>
                <p>Monitor the assessments mentors have written for their mentees.</p>
            </div>
            <div class="ss-hd-actions">
                <a class="ss-export" href="<?= url('admin-assessments-export') ?>?what=assessments">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0 4-4m-4 4-4-4M4 19h16" /></svg>
                    Export <?= $total ?> row<?= $total === 1 ? '' : 's' ?>
                </a>
            </div>
        </div>

        <!-- ══════════ Figures ══════════ -->
        <div class="ss-stats">
            <?php foreach ([
                ['Assessments', number_format($totalAll), $mentorsAll . ' mentor' . ($mentorsAll === 1 ? '' : 's') . ' writing', '#EAF1FB', '#1A5C9A', 'paper'],
                ['Published', number_format($pubAll), $draftAll . ' still in draft', '#E6F5EE', '#17654B', 'check'],
                ['Attempts', number_format($attTotal), $attOpen > 0 ? $attOpen . ' still in progress' : 'All submitted', '#EAF6FB', '#0087CF', 'user'],
                ['Average score', $avgPct !== null ? $avgPct . '%' : '—', $attSub > 0 ? 'Across ' . $attSub . ' submitted attempt' . ($attSub === 1 ? '' : 's') : 'Nothing submitted yet', '#FEF6DC', '#B7791F', 'star'],
            ] as [$k, $v, $s, $bg, $fg, $ico]): ?>
                <div class="ss-stat">
                    <span class="ss-stat-ico" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= in_array($ico, ['paper', 'user'], true) ? as_icon($ico) : ss_icon($ico) ?></span>
                    <div style="min-width:0;">
                        <div class="ss-stat-k"><?= $k ?></div>
                        <div class="ss-stat-v"><?= $v ?></div>
                        <div class="ss-stat-s"><?= htmlspecialchars($s) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ══════════ Tabs ══════════ -->
        <div class="ss-tabs">
            <?php foreach ([
                ['all', 'All', $counts['all_c']],
                ['published', 'Published', $counts['published']],
                ['draft', 'Drafts', $counts['draft']],
                ['attempted', 'With attempts', $counts['attempted']],
                ['untouched', 'Never attempted', $counts['untouched']],
            ] as [$k, $label, $n]): ?>
                <a class="ss-tab <?= $view === $k ? 'on' : '' ?>" href="<?= ab_url(['tab' => $k === 'all' ? null : $k, 'page' => null, 'open' => null]) ?>">
                    <?= $label ?><span class="ss-tab-n"><?= (int)$n ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- ══════════ Filters ══════════ -->
        <form class="ss-filters" method="get" action="<?= url('admin-assessments') ?>">
            <?php if ($view !== 'all'): ?><input type="hidden" name="tab" value="<?= htmlspecialchars($view) ?>"><?php endif; ?>
            <div class="ss-field ss-grow">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="m20 20-4-4" /></svg>
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search title, topic or mentor…">
            </div>
            <div class="ss-field">
                <select name="topic" aria-label="Topic">
                    <option value="">All topics</option>
                    <?php foreach ($topics as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>" <?= $topic === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ss-field">
                <select name="mentor" aria-label="Mentor">
                    <option value="">All mentors</option>
                    <?php foreach ($mentorList as $m): ?>
                        <option value="<?= (int)$m['user_id'] ?>" <?= $mentor === (int)$m['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['nm']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ss-field">
                <select name="sort" aria-label="Sort">
                    <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
                    <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
                    <option value="attempts" <?= $sort === 'attempts' ? 'selected' : '' ?>>Most attempts</option>
                    <option value="score" <?= $sort === 'score' ? 'selected' : '' ?>>Highest average</option>
                    <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>By title</option>
                </select>
            </div>
            <button type="submit" class="ss-apply">Apply</button>
            <?php if ($hasFilter): ?>
                <a class="ss-clear" href="<?= url('admin-assessments') . ($view !== 'all' ? '?tab=' . $view : '') ?>">Clear filters</a>
            <?php endif; ?>
        </form>

        <!-- ══════════ List ══════════ -->
        <?php if (!$rows): ?>
            <div class="ss-empty">
                <?= as_icon('paper') ?>
                <p><?= $hasFilter || $view !== 'all' ? 'No assessments match this view.' : 'No mentor has written an assessment yet.' ?></p>
            </div>
        <?php else: ?>
            <div class="as-list">
                <?php foreach ($rows as $a):
                    [$sl, $sfg, $sbg] = as_status_chip($a['status']);
                    $id = (int)$a['assessment_id'];
                    $avg = $a['avg_pct'] !== null ? round((float)$a['avg_pct']) : null;
                    [$tfg, $tbg] = as_qtype_color('multiple_choice');
                ?>
                    <div class="as-row<?= $open === $id ? ' on' : '' ?>">
                        <span class="as-ico" style="background:<?= $sbg ?>;color:<?= $sfg ?>;"><?= as_icon('paper') ?></span>

                        <div class="as-what">
                            <div class="as-title"><?= htmlspecialchars($a['title'] ?: 'Untitled assessment') ?></div>
                            <div class="as-desc"><?= htmlspecialchars($a['instructions'] ?: 'No instructions were given.') ?></div>
                            <div class="as-chips">
                                <?php if (trim((string)$a['topic']) !== ''): ?>
                                    <span class="as-chip" style="background:#EAF1FB;color:#1A5C9A;"><?= htmlspecialchars($a['topic']) ?></span>
                                <?php endif; ?>
                                <span class="as-chip" style="background:var(--gray-100);color:var(--gray-600);">by <?= htmlspecialchars($a['mentor_name']) ?></span>
                                <span class="as-chip" style="background:var(--gray-100);color:var(--gray-600);"><?= as_limit_label((int)$a['time_limit_minutes']) ?></span>
                            </div>
                        </div>

                        <div class="as-num">
                            <b><?= (int)$a['questions'] ?></b>
                            <span>Question<?= (int)$a['questions'] === 1 ? '' : 's' ?></span>
                        </div>
                        <div class="as-num">
                            <b><?= (int)$a['submitted'] ?></b>
                            <span><?= (int)$a['in_progress'] > 0 ? (int)$a['in_progress'] . ' in progress' : 'Submitted' ?></span>
                        </div>

                        <div class="as-score">
                            <?= ss_icon('star') ?>
                            <div>
                                <b><?= $avg !== null ? $avg . '%' : '—' ?></b>
                                <span><?= $avg !== null ? 'Average' : 'No results' ?></span>
                            </div>
                        </div>

                        <div class="as-when">
                            <?= date('M j, Y', strtotime($a['created_at'])) ?>
                            <span>Created</span>
                        </div>

                        <div class="as-end">
                            <span class="ss-pill" style="color:<?= $sfg ?>;background:<?= $sbg ?>;"><?= $sl ?></span>
                            <a class="ss-view" href="<?= ab_url(['open' => $id]) ?>#panel">View results</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="ss-foot">
                <span>Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= number_format($total) ?> assessment<?= $total === 1 ? '' : 's' ?></span>
                <?php if ($totalPages > 1): ?>
                    <div class="ss-pages">
                        <?php if ($page > 1): ?><a href="<?= ab_url(['page' => $page - 1]) ?>">‹</a><?php else: ?><span class="off">‹</span><?php endif; ?>
                        <?php $lo = max(1, $page - 2); $hi = min($totalPages, $lo + 4); $lo = max(1, $hi - 4);
                        for ($i = $lo; $i <= $hi; $i++): ?>
                            <?php if ($i === $page): ?><span class="on"><?= $i ?></span><?php else: ?><a href="<?= ab_url(['page' => $i]) ?>"><?= $i ?></a><?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?><a href="<?= ab_url(['page' => $page + 1]) ?>">›</a><?php else: ?><span class="off">›</span><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- ══════════ Rail content, below the list on narrow screens ══════════ -->
        <?php if (!$detail): ?>
            <div class="as-rail" style="margin-top:14px;">
                <div class="ss-card">
                    <h2>Recent attempts <a href="<?= url('admin-assessments-results') ?>">Results &amp; analytics →</a></h2>
                    <?php if (!$recent): ?>
                        <p class="ss-none">No mentee has started an assessment yet.</p>
                    <?php else: foreach ($recent as $r):
                        $done = $r['status'] === 'submitted';
                        $pct = ($done && (int)$r['total_points'] > 0) ? round($r['score'] / $r['total_points'] * 100) : null; ?>
                        <a class="as-act" href="<?= ab_url(['open' => (int)$r['assessment_id']]) ?>#panel">
                            <span class="as-act-i" style="background:<?= $done ? '#E6F5EE' : '#FBF0D4' ?>;color:<?= $done ? '#17654B' : '#8A6400' ?>;">
                                <?= $done ? ss_icon('check') : ss_icon('clock') ?>
                            </span>
                            <span style="min-width:0;flex:1;">
                                <b><?= htmlspecialchars($r['mentee_name']) ?> <?= $done ? 'completed' : 'started' ?> <?= htmlspecialchars($r['title']) ?></b>
                                <span><?= as_ago($r['submitted_at'] ?: $r['started_at']) ?><?= $pct !== null ? ' · scored ' . $pct . '%' : '' ?></span>
                            </span>
                        </a>
                    <?php endforeach; endif; ?>
                </div>

                <div class="as-side">
                    <div class="ss-card">
                        <h2>Topics</h2>
                        <?php if (!$byTopic): ?>
                            <p class="ss-none">No assessments yet.</p>
                        <?php else:
                            $cols = ['#1B6FD1', '#17654B', '#6B21A8', '#B7791F', '#C0392B', '#0087CF']; ?>
                            <div class="as-legend">
                                <?php foreach ($byTopic as $i => $t): ?>
                                    <div class="as-legend-row">
                                        <i style="background:<?= $cols[$i % count($cols)] ?>"></i>
                                        <?= htmlspecialchars($t['t']) ?>
                                        <b><?= (int)$t['c'] ?></b>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="ss-card">
                        <h2>Where to look next</h2>
                        <a class="as-act" href="<?= url('admin-assessments-results') ?>">
                            <span class="as-act-i" style="background:#EAF1FB;color:#1A5C9A;"><?= ss_icon('chart') ?></span>
                            <span><b>Results &amp; analytics</b><span>Scores, completion and question difficulty</span></span>
                        </a>
                        <a class="as-act" href="<?= url('admin-assessments-questions') ?>">
                            <span class="as-act-i" style="background:#F3E8FF;color:#6B21A8;"><?= as_icon('quiz') ?></span>
                            <span><b>Question bank</b><span>Every question mentors have written</span></span>
                        </a>
                        <a class="as-act" href="<?= url('admin-assessments-export') ?>?what=results">
                            <span class="as-act-i" style="background:#E6F5EE;color:#17654B;"><?= ss_icon('check') ?></span>
                            <span><b>Export results</b><span>Every attempt as CSV</span></span>
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ══════════ Detail panel ══════════ -->
    <?php if ($detail):
        [$sl, $sfg, $sbg] = as_status_chip($detail['status']);
        $did = (int)$detail['assessment_id'];
        $avg = $detail['avg_pct'] !== null ? round((float)$detail['avg_pct']) : null;
    ?>
        <aside class="ss-panel" id="panel">
            <div class="ss-panel-hd">
                <b>Assessment</b>
                <a class="ss-x" href="<?= ab_url(['open' => null]) ?>" aria-label="Close">&times;</a>
            </div>

            <div class="ss-panel-body">
                <div class="ss-panel-top">
                    <span class="ss-pill" style="color:<?= $sfg ?>;background:<?= $sbg ?>;"><?= $sl ?></span>
                    <span class="ss-ref">#<?= $did ?></span>
                </div>
                <h2 class="ss-panel-title"><?= htmlspecialchars($detail['title'] ?: 'Untitled assessment') ?></h2>
                <?php if (trim((string)$detail['topic']) !== ''): ?>
                    <p class="ss-panel-sub"><?= htmlspecialchars($detail['topic']) ?></p>
                <?php endif; ?>

                <div class="ss-kv">
                    <div><span class="ss-k">Written by</span><span class="ss-v"><a href="<?= url('admin-user') ?>?id=<?= (int)$detail['mentor_id'] ?>" style="color:var(--mint);text-decoration:none;"><?= htmlspecialchars($detail['mentor_name']) ?></a></span></div>
                    <div><span class="ss-k">Created</span><span class="ss-v"><?= date('M j, Y', strtotime($detail['created_at'])) ?></span></div>
                    <div><span class="ss-k">Published</span><span class="ss-v"><?= $detail['published_at'] ? date('M j, Y', strtotime($detail['published_at'])) : 'Not published' ?></span></div>
                    <div><span class="ss-k">Time limit</span><span class="ss-v"><?= as_limit_label((int)$detail['time_limit_minutes']) ?></span></div>
                    <div><span class="ss-k">Questions</span><span class="ss-v"><?= (int)$detail['questions'] ?> · <?= (int)$detail['total_points'] ?> point<?= (int)$detail['total_points'] === 1 ? '' : 's' ?></span></div>
                    <div><span class="ss-k">Average</span><span class="ss-v"><?= $avg !== null ? $avg . '%' : 'No results yet' ?></span></div>
                </div>

                <?php if (trim((string)$detail['instructions']) !== ''): ?>
                    <div class="ss-note">
                        <b>Instructions to the mentee</b>
                        <?= nl2br(htmlspecialchars($detail['instructions'])) ?>
                    </div>
                <?php endif; ?>

                <h3 class="ss-h3">Questions</h3>
                <?php if (!$detailQuestions): ?>
                    <p class="ss-none">This assessment has no questions yet, so nobody can take it.</p>
                <?php else: foreach ($detailQuestions as $i => $qq):
                    $st = as_question_stats($con, (int)$qq['question_id']);
                    [$qfg, $qbg] = as_qtype_color($qq['question_type']); ?>
                    <div class="ss-fb">
                        <div class="ss-fb-hd">
                            <span>Question <?= $i + 1 ?> · <?= as_qtype_label($qq['question_type']) ?></span>
                            <span class="ss-fb-when"><?= (int)$qq['points'] ?> pt<?= (int)$qq['points'] === 1 ? '' : 's' ?><?= (int)$qq['is_required'] ? ' · required' : '' ?></span>
                        </div>
                        <p class="ss-fb-txt" style="font-weight:600;color:var(--gray-800);"><?= htmlspecialchars($qq['question_text']) ?></p>

                        <?php foreach ($qq['options'] as $oi => $o): ?>
                            <div class="as-opt<?= (int)$o['is_correct'] ? ' right' : '' ?>">
                                <span class="as-opt-k"><?= chr(65 + $oi) ?></span>
                                <?= htmlspecialchars($o['option_text']) ?>
                                <?php if ((int)$o['is_correct']): ?><span style="margin-left:auto;font-size:11px;">Correct</span><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$qq['options'] && trim((string)$qq['correct_text']) !== ''): ?>
                            <div class="as-opt right"><span class="as-opt-k">✓</span><?= htmlspecialchars($qq['correct_text']) ?></div>
                        <?php endif; ?>

                        <div class="ss-bar" style="margin-top:10px;">
                            <span>Answered right</span>
                            <span class="ss-bar-t"><i style="width:<?= $st['pct'] ?? 0 ?>%;background:<?= ($st['pct'] ?? 0) >= 60 ? '#17654B' : '#B7791F' ?>"></i></span>
                            <span class="ss-bar-n"><?= $st['pct'] !== null ? $st['pct'] . '%' : '—' ?></span>
                        </div>
                        <div class="ss-fb-by">
                            <?= $st['answered'] ?> answer<?= $st['answered'] === 1 ? '' : 's' ?>
                            <?= $st['flagged'] > 0 ? ' · ' . $st['flagged'] . ' flagged for review' : '' ?>
                        </div>
                    </div>
                <?php endforeach; endif; ?>

                <h3 class="ss-h3">Who has taken it</h3>
                <?php if (!$detailAttempts): ?>
                    <p class="ss-none">No mentee has started this assessment.</p>
                <?php else: ?>
                    <div class="ss-parts">
                        <?php foreach ($detailAttempts as $t):
                            $done = $t['status'] === 'submitted';
                            $pct = ($done && (int)$t['total_points'] > 0) ? round($t['score'] / $t['total_points'] * 100) : null; ?>
                            <a class="ss-part" href="<?= url('admin-user') ?>?id=<?= (int)$t['mentee_id'] ?>">
                                <span class="ss-av"><?= $t['profile_image'] ? '<img src="' . htmlspecialchars($t['profile_image']) . '" alt="">' : htmlspecialchars(strtoupper(substr($t['mentee_name'], 0, 2))) ?></span>
                                <span style="min-width:0;flex:1;">
                                    <b><?= htmlspecialchars($t['mentee_name']) ?></b>
                                    <span class="ss-part-c">
                                        <?= $done ? 'Submitted ' . as_ago($t['submitted_at']) : 'Started ' . as_ago($t['started_at']) . ' — not finished' ?>
                                    </span>
                                </span>
                                <?php if ($pct !== null): ?>
                                    <span class="ss-pill" style="color:<?= $pct >= 60 ? '#17654B' : '#A6301F' ?>;background:<?= $pct >= 60 ? '#E6F5EE' : '#FBE5E1' ?>;">
                                        <?= (int)$t['score'] ?>/<?= (int)$t['total_points'] ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <h3 class="ss-h3">Admin actions</h3>
                <?php // An admin does not write assessments, so the only lever here is
                //     whether a published one stays visible to mentees. ?>
                <div class="ss-acts">
                    <form method="post" action="<?= url('admin-action-assessment') ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="assessment_id" value="<?= $did ?>">
                        <input type="hidden" name="back" value="<?= htmlspecialchars($backHere) ?>">
                        <input type="hidden" name="action" value="<?= $detail['status'] === 'published' ? 'unpublish' : 'publish' ?>">
                        <button type="submit" class="ss-act <?= $detail['status'] === 'published' ? 'warn' : 'ok' ?>">
                            <?= $detail['status'] === 'published' ? ss_icon('x') . 'Take offline' : ss_icon('check') . 'Publish' ?>
                        </button>
                    </form>
                    <a class="ss-act" href="<?= url('messages') ?>?chat=<?= (int)$detail['mentor_id'] ?>"><?= ss_icon('chat') ?>Message the mentor</a>
                </div>
                <p class="ss-none" style="margin-top:9px;">
                    <?= $detail['status'] === 'published'
                        ? 'Taking it offline hides it from mentees who have not started it. Attempts already made are kept.'
                        : 'Publishing makes it available to this mentor’s mentees.' ?>
                </p>
            </div>
        </aside>
    <?php endif; ?>
</div>
