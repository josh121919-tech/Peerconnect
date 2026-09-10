<?php

/**
 * admin/assessments_results.php — Results & Analytics.
 *
 * How the assessments mentors wrote are actually performing: who finished,
 * what they scored, which questions people get wrong, and which mentees are
 * carrying unfinished attempts.
 *
 * Scores are averaged as a percentage of each attempt's own total, never as a
 * raw point average — a 14-point quiz and a 100-point one would otherwise be
 * added together as if they were the same thing.
 *
 * The reference showed an Overdue tile and a PDF export. Assessments here have
 * no due date to be overdue against, and nothing in this install can render a
 * PDF, so both are replaced by things the data supports: abandoned attempts,
 * and CSV.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/assessment_data.php';
require_admin();

date_default_timezone_set('Asia/Manila');

/* ── Range ────────────────────────────────────────────────────────────── */
$presets = ['30d' => 'Last 30 days', '90d' => 'Last 90 days', 'year' => 'This year', 'all' => 'All time'];
$range = array_key_exists($_GET['range'] ?? '', $presets) ? $_GET['range'] : 'all';
switch ($range) {
    case '30d':  $from = date('Y-m-d', strtotime('-29 days')); $to = date('Y-m-d'); break;
    case '90d':  $from = date('Y-m-d', strtotime('-89 days')); $to = date('Y-m-d'); break;
    case 'year': $from = date('Y-01-01'); $to = date('Y-12-31'); break;
    default:     $from = null; $to = null;
}
$rangeLabel = $from ? date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to)) : 'all time';

// Attempts are dated by when they were started, which is the only date every
// attempt has — an unfinished one has no submitted_at at all.
$tw  = $from ? "WHERE DATE(t.started_at) BETWEEN '$from' AND '$to'" : '';
$twA = $from ? "AND DATE(t.started_at) BETWEEN '$from' AND '$to'" : '';

$one = function (string $sql) use ($con) {
    $r = $con->query($sql);
    return $r ? $r->fetch_row()[0] : null;
};

/* ── Headline figures ─────────────────────────────────────────────────── */
$attTotal = (int)$one("SELECT COUNT(*) FROM assessment_attempts t $tw");
$attDone  = (int)$one("SELECT COUNT(*) FROM assessment_attempts t " . ($tw ? "$tw AND" : 'WHERE') . " t.status = 'submitted'");
$attOpen  = $attTotal - $attDone;
$finish   = $attTotal > 0 ? round($attDone / $attTotal * 100, 1) : null;

$avgPct = $one("SELECT AVG(t.score / NULLIF(t.total_points,0) * 100) FROM assessment_attempts t " . ($tw ? "$tw AND" : 'WHERE') . " t.status='submitted'");
$avgPct = $attDone > 0 && $avgPct !== null ? round((float)$avgPct, 1) : null;

$menteeN = (int)$one("SELECT COUNT(DISTINCT t.mentee_id) FROM assessment_attempts t $tw");
$liveN   = (int)$one("SELECT COUNT(*) FROM assessments WHERE status = 'published'");

/* ── Score distribution ───────────────────────────────────────────────── */
$bands = ['90–100%' => 0, '75–89%' => 0, '60–74%' => 0, '40–59%' => 0, 'Under 40%' => 0];
$bq = $con->query("
    SELECT ROUND(t.score / NULLIF(t.total_points,0) * 100) pct
    FROM assessment_attempts t " . ($tw ? "$tw AND" : 'WHERE') . " t.status='submitted' AND t.total_points > 0
");
while ($r = $bq->fetch_assoc()) {
    $p = (int)$r['pct'];
    if ($p >= 90)      $bands['90–100%']++;
    elseif ($p >= 75)  $bands['75–89%']++;
    elseif ($p >= 60)  $bands['60–74%']++;
    elseif ($p >= 40)  $bands['40–59%']++;
    else               $bands['Under 40%']++;
}
$bandTotal = array_sum($bands);

/* ── Per-assessment performance ───────────────────────────────────────── */
$perAssessment = $con->query("
    SELECT a.assessment_id, a.title, a.topic, a.status,
           CONCAT_WS(' ', u.firstname, u.lastname) mentor_name,
           COUNT(t.attempt_id) attempts,
           SUM(t.status = 'submitted') submitted,
           AVG(CASE WHEN t.status = 'submitted' THEN t.score / NULLIF(t.total_points,0) * 100 END) avg_pct
    FROM assessments a
    JOIN users u ON u.user_id = a.mentor_id
    LEFT JOIN assessment_attempts t ON t.assessment_id = a.assessment_id $twA
    GROUP BY a.assessment_id, a.title, a.topic, a.status, mentor_name
    ORDER BY attempts DESC, a.created_at DESC
    LIMIT 8
")->fetch_all(MYSQLI_ASSOC);

/* ── Question difficulty ──────────────────────────────────────────────── */
// Only questions somebody has actually been marked on can be ranked; the
// rest have no difficulty to report yet.
$hardest = $con->query("
    SELECT q.question_id, q.question_text, q.question_type, q.points,
           a.assessment_id, a.title,
           COUNT(an.answer_id) answered,
           SUM(an.is_correct IS NOT NULL) graded,
           SUM(an.is_correct = 1) correct
    FROM assessment_questions q
    JOIN assessments a ON a.assessment_id = q.assessment_id
    JOIN assessment_answers an ON an.question_id = q.question_id
    JOIN assessment_attempts t ON t.attempt_id = an.attempt_id AND t.status = 'submitted' $twA
    GROUP BY q.question_id, q.question_text, q.question_type, q.points, a.assessment_id, a.title
    HAVING graded > 0
    -- MySQL will not let ORDER BY reference an aggregate's alias, so the
    -- ratio is spelled out again rather than reusing `correct / graded`.
    ORDER BY (SUM(an.is_correct = 1) / SUM(an.is_correct IS NOT NULL)) ASC,
             COUNT(an.answer_id) DESC
    LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

/* ── Attempts, the list at the bottom ─────────────────────────────────── */
$fStatus = in_array($_GET['status'] ?? '', ['submitted', 'in_progress'], true) ? $_GET['status'] : '';
$fq      = trim((string)($_GET['q'] ?? ''));

$aClauses = [];
$aTypes = '';
$aArgs = [];
if ($from) { $aClauses[] = "DATE(t.started_at) BETWEEN ? AND ?"; $aTypes .= 'ss'; $aArgs[] = $from; $aArgs[] = $to; }
if ($fStatus !== '') { $aClauses[] = 't.status = ?'; $aTypes .= 's'; $aArgs[] = $fStatus; }
if ($fq !== '') {
    $aClauses[] = "CONCAT_WS(' ', u.firstname, u.lastname, a.title, a.topic) LIKE ?";
    $aTypes .= 's';
    $aArgs[] = '%' . $fq . '%';
}
$aWhere = $aClauses ? 'WHERE ' . implode(' AND ', $aClauses) : '';

$perPage = 8;
$page = max(1, (int)($_GET['page'] ?? 1));

$cSql = "SELECT COUNT(*) c FROM assessment_attempts t
         JOIN assessments a ON a.assessment_id = t.assessment_id
         JOIN users u ON u.user_id = t.mentee_id $aWhere";
if ($aTypes !== '') {
    $cs = $con->prepare($cSql);
    $cs->bind_param($aTypes, ...$aArgs);
    $cs->execute();
    $listTotal = (int)$cs->get_result()->fetch_assoc()['c'];
    $cs->close();
} else {
    $listTotal = (int)$con->query($cSql)->fetch_assoc()['c'];
}
$listPages = max(1, (int)ceil($listTotal / $perPage));
$page = min($page, $listPages);
$offset = ($page - 1) * $perPage;

$ls = $con->prepare("
    SELECT t.attempt_id, t.mentee_id, t.status, t.score, t.total_points, t.started_at, t.submitted_at,
           a.assessment_id, a.title, a.topic,
           CONCAT_WS(' ', u.firstname, u.lastname) mentee_name, p.profile_image,
           u.role
    FROM assessment_attempts t
    JOIN assessments a ON a.assessment_id = t.assessment_id
    JOIN users u ON u.user_id = t.mentee_id
    LEFT JOIN profile p ON p.user_id = t.mentee_id
    $aWhere
    ORDER BY COALESCE(t.submitted_at, t.started_at) DESC
    LIMIT ? OFFSET ?
");
$ls->bind_param($aTypes . 'ii', ...array_merge($aArgs, [$perPage, $offset]));
$ls->execute();
$attempts = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
$ls->close();

/* ── Completion by role ───────────────────────────────────────────────── */
// Only mentees take assessments in this app; the split the reference showed
// between mentee and mentor completion has no second half here, so the card
// reports the one that exists.
$menteesWithAccess = (int)$one("
    SELECT COUNT(DISTINCT sr.mentee_id) FROM session_requests sr
    JOIN assessments a ON a.mentor_id = sr.mentor_id AND a.status = 'published'
");
$menteesAttempted = (int)$one("SELECT COUNT(DISTINCT mentee_id) FROM assessment_attempts");
$reach = $menteesWithAccess > 0 ? round($menteesAttempted / $menteesWithAccess * 100) : null;

function ar_url(array $over = []): string
{
    $p = array_merge([
        'range' => $_GET['range'] ?? null, 'status' => $_GET['status'] ?? null,
        'q' => $_GET['q'] ?? null, 'page' => $_GET['page'] ?? null,
    ], $over);
    $p = array_filter($p, fn($v) => $v !== null && $v !== '');
    return url('admin-assessments-results') . ($p ? '?' . http_build_query($p) : '');
}

$exportQs = http_build_query(array_filter(['what' => 'results', 'from' => $from, 'to' => $to], fn($v) => $v !== null));

$current_page = 'assessments-results';
include 'layout.php';
include __DIR__ . '/includes/assessments_ui.php';
?>

<style>
    .ar-cols { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
    .ar-stack { display: flex; flex-direction: column; gap: 14px; }
    .ar-rank { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-top: 1px solid var(--gray-100); text-decoration: none; }
    .ar-rank:first-of-type { border-top: 0; }
    .ar-rank-n { width: 20px; flex: none; font-size: 12px; font-weight: 700; color: var(--gray-400); }
    .ar-rank-b { flex: 1; min-width: 0; }
    .ar-rank-b b { display: block; font-size: 13px; font-weight: 600; color: var(--gray-800); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ar-rank-b span { font-size: 11.5px; color: var(--gray-400); }
    .ar-rank-v { flex: none; font-size: 14px; font-weight: 700; color: var(--forest); font-variant-numeric: tabular-nums; }
    @media (max-width: 900px) { .ar-cols { grid-template-columns: minmax(0, 1fr); } }
</style>

<div class="ss-hd">
    <div>
        <h1>Results &amp; analytics</h1>
        <p>How the assessments mentors wrote are performing over <?= htmlspecialchars($rangeLabel) ?>.</p>
    </div>
    <div class="ss-hd-actions">
        <a class="ss-export" href="<?= url('admin-assessments-export') ?>?<?= $exportQs ?>">
            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0 4-4m-4 4-4-4M4 19h16" /></svg>
            Export CSV
        </a>
    </div>
</div>

<!-- ══════════ Figures ══════════ -->
<div class="ss-stats">
    <?php foreach ([
        ['Finish rate', $finish !== null ? $finish . '%' : '—', $attTotal > 0 ? $attDone . ' of ' . $attTotal . ' attempts submitted' : 'No attempts yet', '#EAF1FB', '#1A5C9A', 'check'],
        ['Average score', $avgPct !== null ? $avgPct . '%' : '—', $attDone > 0 ? 'Across ' . $attDone . ' submitted attempt' . ($attDone === 1 ? '' : 's') : 'Nothing submitted yet', '#E6F5EE', '#17654B', 'star'],
        ['Mentees taking part', number_format($menteeN), $liveN . ' assessment' . ($liveN === 1 ? '' : 's') . ' published', '#EAF6FB', '#0087CF', 'user'],
        ['Unfinished attempts', number_format($attOpen), $attOpen > 0 ? 'Started but never submitted' : 'Nothing left hanging', $attOpen > 0 ? '#FBEDDD' : '#F3F4F6', $attOpen > 0 ? '#9A4A00' : '#565B66', 'clock'],
    ] as [$k, $v, $s, $bg, $fg, $ico]): ?>
        <div class="ss-stat">
            <span class="ss-stat-ico" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= $ico === 'user' ? as_icon('user') : ss_icon($ico) ?></span>
            <div style="min-width:0;">
                <div class="ss-stat-k"><?= $k ?></div>
                <div class="ss-stat-v"><?= $v ?></div>
                <div class="ss-stat-s"><?= htmlspecialchars($s) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="as-rail">
    <div class="ar-stack">

        <div class="ar-cols">
            <!-- ── Score distribution ── -->
            <div class="ss-card">
                <h2>Score distribution</h2>
                <?php if ($bandTotal === 0): ?>
                    <p class="ss-none">No attempt has been submitted in this range.</p>
                <?php else: ?>
                    <div class="rp-bars" style="display:flex;flex-direction:column;gap:10px;">
                        <?php
                        $bandCols = ['90–100%' => '#17654B', '75–89%' => '#1B6FD1', '60–74%' => '#0087CF', '40–59%' => '#B7791F', 'Under 40%' => '#C0392B'];
                        foreach ($bands as $label => $n):
                            $pct = round($n / $bandTotal * 100); ?>
                            <div class="ss-bar">
                                <span style="width:74px;"><?= $label ?></span>
                                <span class="ss-bar-t"><i style="width:<?= $pct ?>%;background:<?= $bandCols[$label] ?>"></i></span>
                                <span class="ss-bar-n"><?= $n ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="ss-none" style="margin-top:10px;">Each attempt scored against its own total, so assessments of different lengths compare fairly.</p>
                <?php endif; ?>
            </div>

            <!-- ── Hardest questions ── -->
            <div class="ss-card">
                <h2>Hardest questions</h2>
                <?php if (!$hardest): ?>
                    <p class="ss-none">No question has been marked yet, so there is no difficulty to report.</p>
                <?php else: foreach ($hardest as $i => $h):
                    $pct = (int)$h['graded'] > 0 ? round($h['correct'] / $h['graded'] * 100) : null; ?>
                    <a class="ar-rank" href="<?= url('admin-assessments') ?>?open=<?= (int)$h['assessment_id'] ?>#panel">
                        <span class="ar-rank-n"><?= $i + 1 ?></span>
                        <span class="ar-rank-b">
                            <b><?= htmlspecialchars($h['question_text']) ?></b>
                            <span><?= htmlspecialchars($h['title']) ?> · <?= as_qtype_label($h['question_type']) ?> · <?= (int)$h['graded'] ?> marked</span>
                        </span>
                        <span class="ar-rank-v" style="color:<?= $pct !== null && $pct < 60 ? '#A6301F' : '#17654B' ?>;"><?= $pct !== null ? $pct . '%' : '—' ?></span>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- ── Per assessment ── -->
        <div class="ss-card">
            <h2>By assessment <a href="<?= url('admin-assessments') ?>">All assessments →</a></h2>
            <?php if (!$perAssessment): ?>
                <p class="ss-none">No mentor has written an assessment yet.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;">
                        <thead>
                            <tr style="text-align:left;color:var(--gray-400);font-size:11px;text-transform:uppercase;letter-spacing:.05em;">
                                <th style="padding:8px 10px 8px 0;font-weight:700;">Assessment</th>
                                <th style="padding:8px 10px;font-weight:700;">Mentor</th>
                                <th style="padding:8px 10px;font-weight:700;text-align:right;">Attempts</th>
                                <th style="padding:8px 10px;font-weight:700;text-align:right;">Submitted</th>
                                <th style="padding:8px 0 8px 10px;font-weight:700;text-align:right;">Average</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($perAssessment as $r):
                                $avg = $r['avg_pct'] !== null ? round((float)$r['avg_pct']) : null; ?>
                                <tr style="border-top:1px solid var(--gray-100);">
                                    <td style="padding:11px 10px 11px 0;">
                                        <a href="<?= url('admin-assessments') ?>?open=<?= (int)$r['assessment_id'] ?>#panel" style="font-weight:600;color:var(--gray-800);text-decoration:none;"><?= htmlspecialchars($r['title'] ?: 'Untitled') ?></a>
                                        <?php if (trim((string)$r['topic']) !== ''): ?>
                                            <span style="display:block;font-size:11.5px;color:var(--gray-400);"><?= htmlspecialchars($r['topic']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:11px 10px;color:var(--gray-600);"><?= htmlspecialchars($r['mentor_name']) ?></td>
                                    <td style="padding:11px 10px;text-align:right;font-variant-numeric:tabular-nums;"><?= (int)$r['attempts'] ?></td>
                                    <td style="padding:11px 10px;text-align:right;font-variant-numeric:tabular-nums;"><?= (int)$r['submitted'] ?></td>
                                    <td style="padding:11px 0 11px 10px;text-align:right;font-weight:700;color:<?= $avg === null ? 'var(--gray-300)' : ($avg >= 60 ? '#17654B' : '#A6301F') ?>;"><?= $avg !== null ? $avg . '%' : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── Attempts ── -->
        <div class="ss-card">
            <h2>Every attempt</h2>
            <form class="ss-filters" method="get" action="<?= url('admin-assessments-results') ?>" style="margin-bottom:12px;">
                <?php if ($range !== 'all'): ?><input type="hidden" name="range" value="<?= htmlspecialchars($range) ?>"><?php endif; ?>
                <div class="ss-field ss-grow">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="m20 20-4-4" /></svg>
                    <input type="search" name="q" value="<?= htmlspecialchars($fq) ?>" placeholder="Search mentee or assessment…">
                </div>
                <div class="ss-field">
                    <select name="status" aria-label="Status">
                        <option value="">All attempts</option>
                        <option value="submitted" <?= $fStatus === 'submitted' ? 'selected' : '' ?>>Submitted</option>
                        <option value="in_progress" <?= $fStatus === 'in_progress' ? 'selected' : '' ?>>Unfinished</option>
                    </select>
                </div>
                <button type="submit" class="ss-apply">Apply</button>
                <?php if ($fq !== '' || $fStatus !== ''): ?>
                    <a class="ss-clear" href="<?= ar_url(['q' => null, 'status' => null, 'page' => null]) ?>">Clear</a>
                <?php endif; ?>
            </form>

            <?php if (!$attempts): ?>
                <p class="ss-none">No attempts match this view.</p>
            <?php else: ?>
                <div class="as-list">
                    <?php foreach ($attempts as $t):
                        $done = $t['status'] === 'submitted';
                        $pct = ($done && (int)$t['total_points'] > 0) ? round($t['score'] / $t['total_points'] * 100) : null; ?>
                        <div class="as-row" style="box-shadow:none;">
                            <span class="ss-av"><?= $t['profile_image'] ? '<img src="' . htmlspecialchars($t['profile_image']) . '" alt="">' : htmlspecialchars(strtoupper(substr($t['mentee_name'], 0, 2))) ?></span>
                            <div class="as-what">
                                <div class="as-title" style="font-size:13.5px;"><?= htmlspecialchars($t['mentee_name']) ?></div>
                                <div class="as-desc"><?= htmlspecialchars($t['title'] ?: 'Untitled assessment') ?></div>
                                <div class="as-chips">
                                    <span class="as-chip" style="background:var(--gray-100);color:var(--gray-600);"><?= htmlspecialchars(ucfirst($t['role'])) ?></span>
                                    <?php if (trim((string)$t['topic']) !== ''): ?>
                                        <span class="as-chip" style="background:#EAF1FB;color:#1A5C9A;"><?= htmlspecialchars($t['topic']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="as-score">
                                <?= ss_icon('star') ?>
                                <div>
                                    <b><?= $pct !== null ? $pct . '%' : '—' ?></b>
                                    <span><?= $done ? (int)$t['score'] . '/' . (int)$t['total_points'] : 'Not submitted' ?></span>
                                </div>
                            </div>
                            <div class="as-when">
                                <?= date('M j, Y', strtotime($t['submitted_at'] ?: $t['started_at'])) ?>
                                <span><?= $done ? date('g:i A', strtotime($t['submitted_at'])) : 'Started ' . date('g:i A', strtotime($t['started_at'])) ?></span>
                            </div>
                            <div class="as-end">
                                <span class="ss-pill" style="color:<?= $done ? '#17654B' : '#9A4A00' ?>;background:<?= $done ? '#E6F5EE' : '#FBEDDD' ?>;"><?= $done ? 'Completed' : 'Unfinished' ?></span>
                                <a class="ss-view" href="<?= url('admin-user') ?>?id=<?= (int)$t['mentee_id'] ?>">View mentee</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="ss-foot">
                    <span>Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $listTotal) ?> of <?= number_format($listTotal) ?> attempt<?= $listTotal === 1 ? '' : 's' ?></span>
                    <?php if ($listPages > 1): ?>
                        <div class="ss-pages">
                            <?php if ($page > 1): ?><a href="<?= ar_url(['page' => $page - 1]) ?>">‹</a><?php else: ?><span class="off">‹</span><?php endif; ?>
                            <?php $lo = max(1, $page - 2); $hi = min($listPages, $lo + 4); $lo = max(1, $hi - 4);
                            for ($i = $lo; $i <= $hi; $i++): ?>
                                <?php if ($i === $page): ?><span class="on"><?= $i ?></span><?php else: ?><a href="<?= ar_url(['page' => $i]) ?>"><?= $i ?></a><?php endif; ?>
                            <?php endfor; ?>
                            <?php if ($page < $listPages): ?><a href="<?= ar_url(['page' => $page + 1]) ?>">›</a><?php else: ?><span class="off">›</span><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══════════ Side ══════════ -->
    <div class="as-side">
        <div class="ss-card">
            <h2>Range</h2>
            <form method="get" action="<?= url('admin-assessments-results') ?>">
                <div class="rp-field" style="margin-bottom:12px;">
                    <label for="ar-range" style="display:block;font-size:12px;font-weight:600;color:var(--gray-600);margin-bottom:5px;">Attempts started in</label>
                    <select id="ar-range" name="range" style="width:100%;height:38px;padding:0 11px;border:1px solid var(--gray-200);border-radius:10px;font-family:inherit;font-size:13px;color:var(--gray-700);background:#fff;">
                        <?php foreach ($presets as $k => $l): ?>
                            <option value="<?= $k ?>" <?= $range === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="rp-go" style="width:100%;display:inline-flex;align-items:center;justify-content:center;padding:11px;border:0;border-radius:11px;background:var(--forest);color:#fff;font-family:inherit;font-size:13.5px;font-weight:600;cursor:pointer;">Update</button>
            </form>
            <p class="ss-none" style="margin-top:9px;">CSV opens in Excel and Google Sheets. PDF export is not available — this install has no PDF renderer.</p>
        </div>

        <div class="ss-card">
            <h2>Reach</h2>
            <?php if ($reach === null): ?>
                <p class="ss-none">No published assessment has a mentee who could take it yet.</p>
            <?php else: ?>
                <?php $R = 40; $C = 2 * M_PI * $R; $len = $C * ($reach / 100); ?>
                <div style="display:flex;flex-direction:column;align-items:center;gap:8px;">
                    <svg width="104" height="104" viewBox="0 0 104 104" role="img" aria-label="<?= $reach ?> percent of eligible mentees have attempted an assessment">
                        <circle cx="52" cy="52" r="<?= $R ?>" fill="none" stroke="#EDEDED" stroke-width="10" />
                        <circle cx="52" cy="52" r="<?= $R ?>" fill="none" stroke="#1B6FD1" stroke-width="10" stroke-linecap="round"
                                stroke-dasharray="<?= round($len, 2) ?> <?= round($C - $len, 2) ?>" transform="rotate(-90 52 52)" />
                        <text x="52" y="58" text-anchor="middle" font-size="19" font-weight="700" fill="#020547"><?= $reach ?>%</text>
                    </svg>
                    <p class="ss-none" style="text-align:center;">
                        <?= $menteesAttempted ?> of <?= $menteesWithAccess ?> mentee<?= $menteesWithAccess === 1 ? '' : 's' ?> who have a mentor with a published assessment have tried one.
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <div class="ss-card">
            <h2>Question types in use</h2>
            <?php
            $types = $con->query("
                SELECT q.question_type t, COUNT(*) c FROM assessment_questions q GROUP BY q.question_type ORDER BY c DESC
            ")->fetch_all(MYSQLI_ASSOC);
            $tTotal = array_sum(array_column($types, 'c'));
            ?>
            <?php if (!$types): ?>
                <p class="ss-none">No questions have been written yet.</p>
            <?php else: ?>
                <div class="as-legend">
                    <?php foreach ($types as $t): [$fg, ] = as_qtype_color($t['t']); ?>
                        <div class="as-legend-row">
                            <i style="background:<?= $fg ?>"></i><?= as_qtype_label($t['t']) ?><b><?= (int)$t['c'] ?></b>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
