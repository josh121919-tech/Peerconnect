<?php

/**
 * admin/assessments_questions.php — Question Bank.
 *
 * Every question mentors have written, across every assessment, with how it
 * has actually performed.
 *
 * One honest difference from the reference: this app has no reusable question
 * library. A question belongs to exactly one assessment (assessment_questions
 * .assessment_id), so "used in 12 assessments" cannot be true here and is not
 * claimed — each row shows the assessment it belongs to and how many people
 * have answered it. For the same reason there is no Add Question, no import
 * and no category manager: an admin does not author a mentor's paper. What is
 * here instead is the thing an admin can act on — finding questions nobody
 * gets right, questions nobody has answered, and answers flagged for review.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/assessment_data.php';
require_admin();

date_default_timezone_set('Asia/Manila');

/* ── Filters ──────────────────────────────────────────────────────────── */
$VIEWS = ['all', 'answered', 'unanswered', 'hard', 'flagged'];
$view  = in_array($_GET['tab'] ?? '', $VIEWS, true) ? $_GET['tab'] : 'all';

$str = fn(string $k) => is_scalar($_GET[$k] ?? null) ? trim((string)$_GET[$k]) : '';
$q      = $str('q');
$qtype  = in_array($str('type'), AssessmentService::TYPES, true) ? $str('type') : '';
$topic  = $str('topic');
$mentor = (int)$str('mentor');
$sort   = in_array($str('sort'), ['newest', 'answers', 'hardest', 'points'], true) ? $str('sort') : 'newest';

$perPage = 8;
$page    = max(1, (int)$str('page'));

$filters = ['q' => $q, 'qtype' => $qtype, 'topic' => $topic, 'mentor' => $mentor];

/* ── Tab counts, under the same filters ───────────────────────────────── */
$counts = AssessmentAdminRepository::questionTabCounts($con, $filters);

/* ── Headline figures ─────────────────────────────────────────────────── */
$headline = AssessmentAdminRepository::questionHeadline($con);
$qTotal   = $headline['questions'];
$aTotal   = $headline['assessments'];
$authors  = $headline['mentors'];
$pointsAv = $headline['avg_points'] !== null ? round((float)$headline['avg_points'], 1) : null;

// Every answer is marked as it is given, so the rate is out of the answers.
$answers     = AssessmentAdminRepository::answerTotals($con);
$answeredAll = $answers['answered'];
$correctAll  = $answers['correct'];
$correctRate = $answeredAll > 0 ? round($correctAll / $answeredAll * 100) : null;

/* ── The list ─────────────────────────────────────────────────────────── */
$total      = AssessmentAdminRepository::countQuestions($con, $filters, $view);
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$rows = AssessmentAdminRepository::questionPage($con, $filters, $view, $perPage, $offset, $sort);

/* ── Filter options and rail ──────────────────────────────────────────── */
$topics     = AssessmentAdminRepository::questionTopics($con);
$mentorList = AssessmentAdminRepository::questionMentors($con);
$byType     = AssessmentAdminRepository::questionsByType($con);
$typeTotal  = array_sum(array_column($byType, 'c'));
$byTopic    = AssessmentAdminRepository::questionsByTopic($con, 8);

/** This page's address with one filter changed. */
function qb_url(array $over = []): string
{
    $p = array_merge([
        'tab' => $_GET['tab'] ?? null, 'q' => $_GET['q'] ?? null, 'type' => $_GET['type'] ?? null,
        'topic' => $_GET['topic'] ?? null, 'mentor' => $_GET['mentor'] ?? null,
        'sort' => $_GET['sort'] ?? null, 'page' => $_GET['page'] ?? null,
    ], $over);
    $p = array_filter($p, fn($v) => $v !== null && $v !== '' && !is_array($v));
    return url('admin-assessments-questions') . ($p ? '?' . http_build_query($p) : '');
}

$hasFilter = ($q !== '' || $qtype !== '' || $topic !== '' || $mentor > 0);

$current_page = 'assessments-questions';
include 'layout.php';
include __DIR__ . '/includes/assessments_ui.php';
?>

<div class="ss-hd">
    <div>
        <h1>Question bank</h1>
        <p>Every question mentors have written, and how each one is performing.</p>
    </div>
    <div class="ss-hd-actions">
        <a class="ss-export" href="<?= url('admin-assessments-export') ?>?what=questions">
            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0 4-4m-4 4-4-4M4 19h16" /></svg>
            Export <?= $total ?> question<?= $total === 1 ? '' : 's' ?>
        </a>
        <a class="ss-export is-pdf" href="<?= url('admin-assessments-export') ?>?what=questions&amp;format=pdf">
            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V3h12v6M6 18H4v-6h16v6h-2M8 14h8v7H8v-7Z" /></svg>
            Export PDF
        </a>
    </div>
</div>

<!-- ══════════ Figures ══════════ -->
<div class="ss-stats">
    <?php foreach ([
        ['Questions', number_format($qTotal), 'Across ' . $aTotal . ' assessment' . ($aTotal === 1 ? '' : 's'), '#EAF1FB', '#1A5C9A', 'quiz'],
        ['Written by', number_format($authors), 'mentor' . ($authors === 1 ? '' : 's'), '#F3E8FF', '#6B21A8', 'pen'],
        ['Answered right', $correctRate !== null ? $correctRate . '%' : '—', $answeredAll > 0 ? 'Across ' . $answeredAll . ' answer' . ($answeredAll === 1 ? '' : 's') : 'Nothing marked yet', '#E6F5EE', '#17654B', 'target'],
        ['Average worth', $pointsAv !== null ? $pointsAv . ' pts' : '—', 'Per question', '#FEF6DC', '#B7791F', 'star'],
    ] as [$k, $v, $s, $bg, $fg, $ico]): ?>
        <div class="ss-stat">
            <span class="ss-stat-ico" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= $ico === 'star' ? ss_icon('star') : as_icon($ico) ?></span>
            <div style="min-width:0;">
                <div class="ss-stat-k"><?= $k ?></div>
                <div class="ss-stat-v"><?= $v ?></div>
                <div class="ss-stat-s"><?= htmlspecialchars($s) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="as-rail">
    <div>
        <!-- ══════════ Tabs ══════════ -->
        <div class="ss-tabs">
            <?php foreach ([
                ['all', 'All questions', $counts['all_c']],
                ['answered', 'Answered', $counts['answered']],
                ['unanswered', 'Never answered', $counts['unanswered']],
                ['hard', 'Under 60% right', $counts['hard']],
                ['flagged', 'Flagged', $counts['flagged']],
            ] as [$k, $label, $n]): ?>
                <a class="ss-tab <?= $view === $k ? 'on' : '' ?>" href="<?= qb_url(['tab' => $k === 'all' ? null : $k, 'page' => null]) ?>">
                    <?= $label ?><span class="ss-tab-n"><?= (int)$n ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- ══════════ Filters ══════════ -->
        <form class="ss-filters" method="get" action="<?= url('admin-assessments-questions') ?>">
            <?php if ($view !== 'all'): ?><input type="hidden" name="tab" value="<?= htmlspecialchars($view) ?>"><?php endif; ?>
            <div class="ss-field ss-grow">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="m20 20-4-4" /></svg>
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search question text, hint, assessment or topic…">
            </div>
            <div class="ss-field">
                <select name="type" aria-label="Question type">
                    <option value="">All types</option>
                    <?php foreach (['multiple_choice', 'true_false', 'short_answer'] as $t): ?>
                        <option value="<?= $t ?>" <?= $qtype === $t ? 'selected' : '' ?>><?= as_qtype_label($t) ?></option>
                    <?php endforeach; ?>
                </select>
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
                    <option value="answers" <?= $sort === 'answers' ? 'selected' : '' ?>>Most answered</option>
                    <option value="hardest" <?= $sort === 'hardest' ? 'selected' : '' ?>>Hardest first</option>
                    <option value="points" <?= $sort === 'points' ? 'selected' : '' ?>>Worth the most</option>
                </select>
            </div>
            <button type="submit" class="ss-apply">Apply</button>
            <?php if ($hasFilter): ?>
                <a class="ss-clear" href="<?= url('admin-assessments-questions') . ($view !== 'all' ? '?tab=' . $view : '') ?>">Clear filters</a>
            <?php endif; ?>
        </form>

        <!-- ══════════ List ══════════ -->
        <?php if (!$rows): ?>
            <div class="ss-empty">
                <?= as_icon('quiz') ?>
                <p><?= $hasFilter || $view !== 'all' ? 'No questions match this view.' : 'No mentor has written a question yet.' ?></p>
            </div>
        <?php else: ?>
            <div class="as-list">
                <?php foreach ($rows as $r):
                    [$tfg, $tbg] = as_qtype_color($r['question_type']);
                    $pct = (int)$r['answered'] > 0 ? round($r['correct'] / $r['answered'] * 100) : null;
                ?>
                    <div class="as-q">
                        <span class="as-q-ico" style="background:<?= $tbg ?>;color:<?= $tfg ?>;"><?= as_icon('quiz') ?></span>

                        <div class="as-q-main">
                            <div class="as-q-text"><?= htmlspecialchars($r['question_text']) ?></div>
                            <?php if (trim((string)$r['hint']) !== ''): ?>
                                <div class="as-desc" style="margin-top:3px;">Hint: <?= htmlspecialchars($r['hint']) ?></div>
                            <?php endif; ?>
                            <div class="as-chips">
                                <span class="as-chip" style="background:<?= $tbg ?>;color:<?= $tfg ?>;"><?= as_qtype_label($r['question_type']) ?></span>
                                <?php if (trim((string)$r['topic']) !== ''): ?>
                                    <span class="as-chip" style="background:#EAF1FB;color:#1A5C9A;"><?= htmlspecialchars($r['topic']) ?></span>
                                <?php endif; ?>
                                <span class="as-chip" style="background:var(--gray-100);color:var(--gray-600);"><?= (int)$r['is_required'] ? 'Required' : 'Optional' ?></span>
                                <span class="as-chip" style="background:var(--gray-100);color:var(--gray-600);"><?= (int)$r['points'] ?> pt<?= (int)$r['points'] === 1 ? '' : 's' ?></span>
                                <?php if ((int)$r['flagged'] > 0): ?>
                                    <span class="as-chip" style="background:#FBE5E1;color:#A6301F;"><?= (int)$r['flagged'] ?> flagged</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="as-q-meta">
                            <a href="<?= url('admin-assessments') ?>?open=<?= (int)$r['assessment_id'] ?>#panel" style="text-decoration:none;">
                                <b><?= htmlspecialchars($r['assessment_title'] ?: 'Untitled') ?></b>
                            </a>
                            <span style="display:block;font-size:11.5px;color:var(--gray-400);">by <?= htmlspecialchars($r['mentor_name']) ?></span>
                            <?php if ((int)$r['options_n'] > 0): ?>
                                <span style="display:block;font-size:11.5px;color:var(--gray-400);"><?= (int)$r['options_n'] ?> options</span>
                            <?php endif; ?>
                        </div>

                        <div class="as-q-rate">
                            <?php if ($pct === null): ?>
                                <b style="color:var(--gray-300);">—</b>
                                <span><?= (int)$r['answered'] > 0 ? (int)$r['answered'] . ' answered, none marked' : 'Never answered' ?></span>
                            <?php else: ?>
                                <b style="color:<?= $pct >= 60 ? '#17654B' : '#A6301F' ?>;"><?= $pct ?>%</b>
                                <span>right, from <?= (int)$r['answered'] ?> answered</span>
                                <div class="as-q-bar"><i style="width:<?= $pct ?>%;background:<?= $pct >= 60 ? '#17654B' : '#C0392B' ?>"></i></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="ss-foot">
                <span>Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= number_format($total) ?> question<?= $total === 1 ? '' : 's' ?></span>
                <?php pc_pagination($page, $totalPages, fn(int $n) => qb_url(['page' => $n]), ['label' => 'Question bank pages']); ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ══════════ Side ══════════ -->
    <div class="as-side">
        <div class="ss-card">
            <h2>Question types</h2>
            <?php if (!$byType): ?>
                <p class="ss-none">No questions written yet.</p>
            <?php else: foreach ($byType as $t): [$fg, ] = as_qtype_color($t['t']);
                $pct = $typeTotal > 0 ? round($t['c'] / $typeTotal * 100) : 0; ?>
                <div class="ss-bar" style="margin-bottom:9px;">
                    <span style="width:104px;"><?= as_qtype_label($t['t']) ?></span>
                    <span class="ss-bar-t"><i style="width:<?= $pct ?>%;background:<?= $fg ?>"></i></span>
                    <span class="ss-bar-n"><?= (int)$t['c'] ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="ss-card">
            <h2>Topics</h2>
            <?php if (!$byTopic): ?>
                <p class="ss-none">No questions written yet.</p>
            <?php else:
                $cols = ['#1B6FD1', '#17654B', '#6B21A8', '#B7791F', '#C0392B', '#087FC1', '#1A5C9A', '#565B66']; ?>
                <div class="as-legend">
                    <?php foreach ($byTopic as $i => $t): ?>
                        <a class="as-legend-row" style="text-decoration:none;" href="<?= qb_url(['topic' => $t['t'] === 'No topic set' ? null : $t['t'], 'page' => null]) ?>">
                            <i style="background:<?= $cols[$i % count($cols)] ?>"></i><?= htmlspecialchars($t['t']) ?><b><?= (int)$t['c'] ?></b>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="ss-card">
            <h2>About this page</h2>
            <p class="ss-none" style="line-height:1.6;">
                Questions in PeerConnect belong to the assessment they were written for — there is no shared library to add to,
                so this page lists and measures rather than edits. To change a question, the mentor who wrote it edits their own assessment.
            </p>
            <a class="as-act" style="margin-top:10px;" href="<?= url('admin-assessments') ?>">
                <span class="as-act-i" style="background:#EAF1FB;color:#1A5C9A;"><?= as_icon('paper') ?></span>
                <span><b>All assessments</b><span>See each paper and its results</span></span>
            </a>
            <a class="as-act" href="<?= url('admin-assessments-results') ?>">
                <span class="as-act-i" style="background:#E6F5EE;color:#17654B;"><?= ss_icon('chart') ?></span>
                <span><b>Results &amp; analytics</b><span>Scores, finish rate and difficulty</span></span>
            </a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/layout_end.php'; ?>
