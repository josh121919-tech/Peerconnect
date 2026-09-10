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

$q      = trim((string)($_GET['q'] ?? ''));
$qtype  = in_array($_GET['type'] ?? '', ['multiple_choice', 'true_false', 'short_answer'], true) ? $_GET['type'] : '';
$topic  = trim((string)($_GET['topic'] ?? ''));
$mentor = (int)($_GET['mentor'] ?? 0);
$sort   = in_array($_GET['sort'] ?? '', ['newest', 'answers', 'hardest', 'points'], true) ? $_GET['sort'] : 'newest';

$perPage = 8;
$page    = max(1, (int)($_GET['page'] ?? 1));

/*
 * The answer counts every view needs. Counted only over submitted attempts —
 * an answer inside an attempt somebody abandoned is not a result.
 */
$stats = "
    LEFT JOIN (
        SELECT an.question_id,
               COUNT(*) answered,
               SUM(an.is_correct IS NOT NULL) graded,
               SUM(an.is_correct = 1) correct,
               SUM(an.is_flagged = 1) flagged
        FROM assessment_answers an
        JOIN assessment_attempts t ON t.attempt_id = an.attempt_id AND t.status = 'submitted'
        GROUP BY an.question_id
    ) s ON s.question_id = q.question_id
";

$clauses = [];
$types   = '';
$args    = [];

if ($q !== '') {
    $clauses[] = "CONCAT_WS(' ', q.question_text, q.hint, a.title, a.topic) LIKE ?";
    $types .= 's';
    $args[] = '%' . $q . '%';
}
if ($qtype !== '')  { $clauses[] = 'q.question_type = ?'; $types .= 's'; $args[] = $qtype; }
if ($topic !== '')  { $clauses[] = 'a.topic = ?';         $types .= 's'; $args[] = $topic; }
if ($mentor > 0)    { $clauses[] = 'a.mentor_id = ?';     $types .= 'i'; $args[] = $mentor; }

$viewSql = [
    'answered'   => 'COALESCE(s.answered, 0) > 0',
    'unanswered' => 'COALESCE(s.answered, 0) = 0',
    'hard'       => 'COALESCE(s.graded, 0) > 0 AND (s.correct / s.graded) < 0.6',
    'flagged'    => 'COALESCE(s.flagged, 0) > 0',
][$view] ?? '';

$all = $clauses;
if ($viewSql !== '') $all[] = $viewSql;
$where = $all ? 'WHERE ' . implode(' AND ', $all) : '';

$base = "
    FROM assessment_questions q
    JOIN assessments a ON a.assessment_id = q.assessment_id
    JOIN users u ON u.user_id = a.mentor_id
    $stats
";

/* ── Tab counts ───────────────────────────────────────────────────────── */
$filterWhere = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
$cSql = "
    SELECT COUNT(*) all_c,
           SUM(COALESCE(s.answered,0) > 0) answered,
           SUM(COALESCE(s.answered,0) = 0) unanswered,
           SUM(COALESCE(s.graded,0) > 0 AND (s.correct / s.graded) < 0.6) hard,
           SUM(COALESCE(s.flagged,0) > 0) flagged
    $base $filterWhere
";
if ($types !== '') {
    $cs = $con->prepare($cSql);
    $cs->bind_param($types, ...$args);
    $cs->execute();
    $counts = $cs->get_result()->fetch_assoc();
    $cs->close();
} else {
    $counts = $con->query($cSql)->fetch_assoc();
}
foreach ($counts as $k => $v) $counts[$k] = (int)$v;

/* ── Headline figures ─────────────────────────────────────────────────── */
$one = function (string $sql) use ($con) {
    $r = $con->query($sql);
    return $r ? $r->fetch_row()[0] : null;
};
$qTotal   = (int)$one("SELECT COUNT(*) FROM assessment_questions");
$aTotal   = (int)$one("SELECT COUNT(DISTINCT assessment_id) FROM assessment_questions");
$authors  = (int)$one("SELECT COUNT(DISTINCT a.mentor_id) FROM assessment_questions q JOIN assessments a ON a.assessment_id = q.assessment_id");
$pointsAv = $one("SELECT AVG(points) FROM assessment_questions");
$pointsAv = $pointsAv !== null ? round((float)$pointsAv, 1) : null;

$gradedAll = (int)$one("
    SELECT COUNT(*) FROM assessment_answers an
    JOIN assessment_attempts t ON t.attempt_id = an.attempt_id AND t.status='submitted'
    WHERE an.is_correct IS NOT NULL
");
$correctAll = (int)$one("
    SELECT COUNT(*) FROM assessment_answers an
    JOIN assessment_attempts t ON t.attempt_id = an.attempt_id AND t.status='submitted'
    WHERE an.is_correct = 1
");
$correctRate = $gradedAll > 0 ? round($correctAll / $gradedAll * 100) : null;

/* ── The list ─────────────────────────────────────────────────────────── */
$cs2 = $con->prepare("SELECT COUNT(*) c $base $where");
if ($types !== '') $cs2->bind_param($types, ...$args);
$cs2->execute();
$total = (int)$cs2->get_result()->fetch_assoc()['c'];
$cs2->close();

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$order = [
    'newest'  => 'q.question_id DESC',
    'answers' => 'COALESCE(s.answered,0) DESC, q.question_id DESC',
    'hardest' => 'CASE WHEN COALESCE(s.graded,0) > 0 THEN s.correct / s.graded ELSE 2 END ASC, COALESCE(s.answered,0) DESC',
    'points'  => 'q.points DESC, q.question_id DESC',
][$sort];

$ls = $con->prepare("
    SELECT q.question_id, q.assessment_id, q.question_type, q.question_text, q.hint,
           q.points, q.is_required, q.correct_text, q.question_order,
           a.title AS assessment_title, a.topic, a.status AS assessment_status,
           a.mentor_id, CONCAT_WS(' ', u.firstname, u.lastname) AS mentor_name,
           COALESCE(s.answered, 0) answered, COALESCE(s.graded, 0) graded,
           COALESCE(s.correct, 0) correct, COALESCE(s.flagged, 0) flagged,
           (SELECT COUNT(*) FROM assessment_options o WHERE o.question_id = q.question_id) options_n
    $base $where
    ORDER BY $order
    LIMIT ? OFFSET ?
");
$ls->bind_param($types . 'ii', ...array_merge($args, [$perPage, $offset]));
$ls->execute();
$rows = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
$ls->close();

/* ── Filter options and rail ──────────────────────────────────────────── */
$topics = [];
$tq = $con->query("SELECT DISTINCT a.topic FROM assessments a JOIN assessment_questions q ON q.assessment_id = a.assessment_id WHERE a.topic <> '' ORDER BY a.topic");
while ($r = $tq->fetch_row()) $topics[] = $r[0];

$mentorList = $con->query("
    SELECT DISTINCT u.user_id, CONCAT_WS(' ', u.firstname, u.lastname) nm
    FROM assessment_questions q
    JOIN assessments a ON a.assessment_id = q.assessment_id
    JOIN users u ON u.user_id = a.mentor_id ORDER BY nm
")->fetch_all(MYSQLI_ASSOC);

$byType = $con->query("SELECT question_type t, COUNT(*) c FROM assessment_questions GROUP BY question_type ORDER BY c DESC")->fetch_all(MYSQLI_ASSOC);
$typeTotal = array_sum(array_column($byType, 'c'));

$byTopic = $con->query("
    SELECT COALESCE(NULLIF(a.topic,''), 'No topic set') t, COUNT(*) c
    FROM assessment_questions q JOIN assessments a ON a.assessment_id = q.assessment_id
    GROUP BY t ORDER BY c DESC LIMIT 8
")->fetch_all(MYSQLI_ASSOC);

function qb_url(array $over = []): string
{
    $p = array_merge([
        'tab' => $_GET['tab'] ?? null, 'q' => $_GET['q'] ?? null, 'type' => $_GET['type'] ?? null,
        'topic' => $_GET['topic'] ?? null, 'mentor' => $_GET['mentor'] ?? null,
        'sort' => $_GET['sort'] ?? null, 'page' => $_GET['page'] ?? null,
    ], $over);
    $p = array_filter($p, fn($v) => $v !== null && $v !== '');
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
    </div>
</div>

<!-- ══════════ Figures ══════════ -->
<div class="ss-stats">
    <?php foreach ([
        ['Questions', number_format($qTotal), 'Across ' . $aTotal . ' assessment' . ($aTotal === 1 ? '' : 's'), '#EAF1FB', '#1A5C9A', 'quiz'],
        ['Written by', number_format($authors), 'mentor' . ($authors === 1 ? '' : 's'), '#F3E8FF', '#6B21A8', 'pen'],
        ['Answered right', $correctRate !== null ? $correctRate . '%' : '—', $gradedAll > 0 ? 'Across ' . $gradedAll . ' marked answer' . ($gradedAll === 1 ? '' : 's') : 'Nothing marked yet', '#E6F5EE', '#17654B', 'target'],
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
                    $pct = (int)$r['graded'] > 0 ? round($r['correct'] / $r['graded'] * 100) : null;
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
                                <span>right, from <?= (int)$r['graded'] ?> marked</span>
                                <div class="as-q-bar"><i style="width:<?= $pct ?>%;background:<?= $pct >= 60 ? '#17654B' : '#C0392B' ?>"></i></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="ss-foot">
                <span>Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= number_format($total) ?> question<?= $total === 1 ? '' : 's' ?></span>
                <?php if ($totalPages > 1): ?>
                    <div class="ss-pages">
                        <?php if ($page > 1): ?><a href="<?= qb_url(['page' => $page - 1]) ?>">‹</a><?php else: ?><span class="off">‹</span><?php endif; ?>
                        <?php $lo = max(1, $page - 2); $hi = min($totalPages, $lo + 4); $lo = max(1, $hi - 4);
                        for ($i = $lo; $i <= $hi; $i++): ?>
                            <?php if ($i === $page): ?><span class="on"><?= $i ?></span><?php else: ?><a href="<?= qb_url(['page' => $i]) ?>"><?= $i ?></a><?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?><a href="<?= qb_url(['page' => $page + 1]) ?>">›</a><?php else: ?><span class="off">›</span><?php endif; ?>
                    </div>
                <?php endif; ?>
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
                $cols = ['#1B6FD1', '#17654B', '#6B21A8', '#B7791F', '#C0392B', '#0087CF', '#1A5C9A', '#565B66']; ?>
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
