<?php
// Who took one of my assessments, and how they did (mentor only).
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mentor') {
    header("Location: " . url('welcomepage'));
    exit;
}

$mentor_id     = (int)$_SESSION['user_id'];
$assessment_id = (int)($_GET['id'] ?? 0);

$stmt = $con->prepare("SELECT * FROM assessments WHERE assessment_id = ? AND mentor_id = ?");
$stmt->bind_param("ii", $assessment_id, $mentor_id);
$stmt->execute();
$assessment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$assessment) {
    pc_flash('error', 'That assessment could not be found.');
    header("Location: " . url('assessments'));
    exit;
}

$attempts = $con->query("
    SELECT at.*, u.firstname, u.lastname
    FROM assessment_attempts at
    JOIN users u ON u.user_id = at.mentee_id
    WHERE at.assessment_id = $assessment_id AND at.status = 'submitted'
    ORDER BY at.submitted_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Per-question accuracy across everyone who submitted.
$per_question = $con->query("
    SELECT q.question_id, q.question_order, q.question_text, q.points,
           COUNT(ans.answer_id) AS answered,
           SUM(ans.is_correct)  AS correct
    FROM assessment_questions q
    LEFT JOIN assessment_answers ans ON ans.question_id = q.question_id
         AND ans.attempt_id IN (SELECT attempt_id FROM assessment_attempts
                                 WHERE assessment_id = $assessment_id AND status = 'submitted')
    WHERE q.assessment_id = $assessment_id
    GROUP BY q.question_id, q.question_order, q.question_text, q.points
    ORDER BY q.question_order ASC
")->fetch_all(MYSQLI_ASSOC);

$avg_percent = 0;
if ($attempts) {
    $sum = 0;
    foreach ($attempts as $a) {
        $sum += (int)$a['total_points'] > 0 ? ($a['score'] / $a['total_points'] * 100) : 0;
    }
    $avg_percent = round($sum / count($attempts));
}

$active_page = 'assessments';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Results — <?= htmlspecialchars($assessment['title']) ?></title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <style>
        .rs-bar-track {
            height: 7px;
            border-radius: 999px;
            background: var(--gray-100);
            overflow: hidden;
            flex: 1;
            min-width: 90px;
        }

        .rs-bar-fill {
            height: 100%;
            border-radius: 999px;
            background: var(--mint);
        }
    </style>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/../includes/app_shell.php'; ?>

        <main class="main fade-in">
            <a href="<?= htmlspecialchars(url('assessments')) ?>" class="btn btn-ghost btn-sm" style="margin-bottom:18px;">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                Back to Assessments
            </a>

            <div class="page-hd">
                <h1><?= htmlspecialchars($assessment['title']) ?></h1>
                <p><?= htmlspecialchars($assessment['topic']) ?> · <?= count($attempts) ?> submission<?= count($attempts) === 1 ? '' : 's' ?></p>
            </div>

            <div class="stats-grid">
                <div class="stat-card stat-card-icon">
                    <div class="stat-icon si-teal">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" /></svg>
                    </div>
                    <div>
                        <div class="stat-val"><?= count($attempts) ?></div>
                        <div class="stat-lbl">Submissions</div>
                    </div>
                </div>
                <div class="stat-card stat-card-icon">
                    <div class="stat-icon si-orange">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19h16M7 16V9m5 7V5m5 11v-4" /></svg>
                    </div>
                    <div>
                        <div class="stat-val"><?= $avg_percent ?>%</div>
                        <div class="stat-lbl">Average Score</div>
                    </div>
                </div>
                <div class="stat-card stat-card-icon">
                    <div class="stat-icon si-purple">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>
                    </div>
                    <div>
                        <div class="stat-val"><?= count($per_question) ?></div>
                        <div class="stat-lbl">Questions</div>
                    </div>
                </div>
                <div class="stat-card stat-card-icon">
                    <div class="stat-icon si-blue">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7v5l3 3" /></svg>
                    </div>
                    <div>
                        <div class="stat-val"><?= $assessment['time_limit_minutes'] ? (int)$assessment['time_limit_minutes'] : '—' ?></div>
                        <div class="stat-lbl">Time Limit (min)</div>
                    </div>
                </div>
            </div>

            <div class="dash-grid">
                <div class="pcard">
                    <div class="pcard-hd"><span class="pcard-title">Submissions</span></div>
                    <?php if (empty($attempts)): ?>
                        <div class="prow-empty">No mentee has submitted this assessment yet.</div>
                    <?php else: ?>
                        <?php foreach ($attempts as $a):
                            $pct = (int)$a['total_points'] > 0 ? round($a['score'] / $a['total_points'] * 100) : 0;
                            $name = trim($a['firstname'] . ' ' . $a['lastname']);
                        ?>
                            <div class="prow">
                                <div class="pc-avatar pc-avatar-md" style="flex-shrink:0;">
                                    <?= htmlspecialchars(strtoupper(substr($a['firstname'], 0, 1) . substr($a['lastname'], 0, 1))) ?>
                                </div>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-size:13.5px;font-weight:700;color:var(--gray-900);"><?= htmlspecialchars($name) ?></div>
                                    <div style="font-size:11.5px;color:var(--gray-400);">Submitted <?= date('M j, Y g:i A', strtotime($a['submitted_at'])) ?></div>
                                </div>
                                <div style="text-align:right;flex-shrink:0;">
                                    <div style="font-size:17px;font-weight:700;color:<?= $pct >= 70 ? 'var(--success)' : ($pct >= 40 ? 'var(--warning)' : 'var(--danger)') ?>;"><?= $pct ?>%</div>
                                    <div style="font-size:11.5px;color:var(--gray-400);"><?= (int)$a['score'] ?>/<?= (int)$a['total_points'] ?> pts</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="pcard">
                    <div class="pcard-hd"><span class="pcard-title">Per-question accuracy</span></div>
                    <?php if (empty($attempts)): ?>
                        <div class="prow-empty">Accuracy appears once mentees submit.</div>
                    <?php else: ?>
                        <div class="pcard-body" style="display:flex;flex-direction:column;gap:14px;">
                            <?php foreach ($per_question as $q):
                                $answered = (int)$q['answered'];
                                $correct  = (int)$q['correct'];
                                $pct      = $answered > 0 ? round($correct / $answered * 100) : 0;
                            ?>
                                <div>
                                    <div style="font-size:12.5px;color:var(--gray-700);margin-bottom:6px;">
                                        <?= (int)$q['question_order'] ?>. <?= htmlspecialchars(mb_strimwidth($q['question_text'], 0, 70, '…')) ?>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <div class="rs-bar-track">
                                            <div class="rs-bar-fill" style="width:<?= $pct ?>%;background:<?= $pct >= 70 ? 'var(--success)' : ($pct >= 40 ? 'var(--warning)' : 'var(--danger)') ?>;"></div>
                                        </div>
                                        <span style="font-size:12px;font-weight:700;color:var(--gray-700);min-width:80px;text-align:right;">
                                            <?= $correct ?>/<?= $answered ?> correct
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
        function toggleProfileMenu() {
            const m = document.getElementById('profileMenu');
            if (m) m.classList.toggle('open');
        }
        document.addEventListener('click', function(e) {
            const m = document.getElementById('profileMenu');
            if (m && !e.target.closest('#profileMenu') && !e.target.closest('button[onclick="toggleProfileMenu()"]')) m.classList.remove('open');
        });
    </script>
</body>

</html>
