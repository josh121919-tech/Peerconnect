<?php
// Assessments — one list page for both roles.
// Mentors see the assessments they wrote; mentees see the published ones from
// mentors they actually have sessions with, plus their own results.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['mentee', 'mentor'], true)) {
    header("Location: " . url('welcomepage'));
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$is_mentor = $role === 'mentor';


if ($is_mentor) {
    // Everything this mentor wrote, with how it has been used.
    $rows = AssessmentRepository::forMentor($con, $user_id);

    $published = array_filter($rows, fn($r) => $r['status'] === 'published');
    $drafts    = array_filter($rows, fn($r) => $r['status'] === 'draft');
    $total_submissions = array_sum(array_column($rows, 'submissions'));
} else {
    // Published assessments from mentors this mentee has worked with, each
    // with their latest attempt — an assessment can be taken again, so the
    // latest is what the card shows and the rest are history.
    $rows = AssessmentRepository::forMentee($con, $user_id);

    $todo = array_filter($rows, fn($r) => ($r['attempt_status'] ?? '') !== 'submitted');
    $done = array_filter($rows, fn($r) => ($r['attempt_status'] ?? '') === 'submitted');
}

$active_page = 'assessments';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessments — PeerConnect</title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <style>
        .as-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
        }

        .as-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 20px;
            display: flex;
            flex-direction: column;
        }

        .as-topic {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            color: var(--forest);
            background: var(--mint-faint);
            border-radius: 999px;
            padding: 3px 10px;
            margin-bottom: 10px;
        }

        .as-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--forest);
            line-height: 1.35;
            margin-bottom: 6px;
        }

        .as-sub {
            font-size: 12.5px;
            color: var(--gray-500);
            line-height: 1.5;
            margin-bottom: 14px;
        }

        .as-meta {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            font-size: 11.5px;
            color: var(--gray-500);
            padding-top: 12px;
            border-top: 1px solid var(--border);
            margin-top: auto;
        }

        .as-meta span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .as-meta svg {
            width: 13px;
            height: 13px;
            color: var(--gray-400);
        }

        .as-actions {
            display: flex;
            gap: 8px;
            margin-top: 14px;
        }

        .as-actions .btn {
            flex: 1;
            justify-content: center;
            font-size: 12.5px;
        }

        .as-score {
            display: flex;
            align-items: baseline;
            gap: 6px;
            margin-bottom: 12px;
        }

        .as-score b {
            font-size: 24px;
            font-weight: 700;
            color: var(--forest);
            line-height: 1;
        }

        .as-section-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--forest);
            margin: 24px 0 12px;
        }

        .as-section-title:first-of-type {
            margin-top: 0;
        }

        .as-flash {
            border-radius: var(--radius);
            padding: 11px 16px;
            font-size: 13px;
            margin-bottom: 16px;
        }

        .as-flash-success {
            background: var(--success-bg);
            color: var(--success);
        }

        .as-flash-error {
            background: var(--danger-bg);
            color: var(--danger);
        }
    </style>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/../includes/app_shell.php'; ?>

        <main class="main fade-in">
            <div class="page-hd" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
                <div>
                    <h1>Assessments</h1>
                    <p>
                        <?= $is_mentor
                            ? 'Create assessments to check how well your mentees understand a topic.'
                            : 'Assessments your mentors have shared with you. Track what you have learned.' ?>
                    </p>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-top:4px;">
                    <?php if ($is_mentor): ?>
                        <a href="<?= htmlspecialchars(url('assessment-create')) ?>" class="btn btn-primary btn-sm">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                            Create Assessment
                        </a>
                    <?php endif; ?>                </div>
            </div>


            <?php if ($is_mentor): ?>
                <div class="stats-grid">
                    <div class="stat-card stat-card-icon">
                        <div class="stat-icon si-teal">
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5M9 13h6M9 17h4" /></svg>
                        </div>
                        <div>
                            <div class="stat-val"><?= count($published) ?></div>
                            <div class="stat-lbl">Published</div>
                        </div>
                    </div>
                    <div class="stat-card stat-card-icon">
                        <div class="stat-icon si-purple">
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20 16.5 7.5l3 3L7 23H4v-3Z" /><path stroke-linecap="round" d="M13.5 10.5 16.5 13.5" /></svg>
                        </div>
                        <div>
                            <div class="stat-val"><?= count($drafts) ?></div>
                            <div class="stat-lbl">Drafts</div>
                        </div>
                    </div>
                    <div class="stat-card stat-card-icon">
                        <div class="stat-icon si-orange">
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                        <div>
                            <div class="stat-val"><?= (int)$total_submissions ?></div>
                            <div class="stat-lbl">Submissions</div>
                        </div>
                    </div>
                    <div class="stat-card stat-card-icon">
                        <div class="stat-icon si-blue">
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19h16M7 16V9m5 7V5m5 11v-4" /></svg>
                        </div>
                        <div>
                            <div class="stat-val"><?= count($rows) ?></div>
                            <div class="stat-lbl">Total</div>
                        </div>
                    </div>
                </div>

                <?php if (empty($rows)): ?>
                    <div class="pcard">
                        <div class="prow-empty">
                            <p style="margin:0 0 12px;">You haven't created any assessments yet.</p>
                            <a href="<?= htmlspecialchars(url('assessment-create')) ?>" class="btn btn-primary btn-sm">Create your first assessment</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="as-grid">
                        <?php foreach ($rows as $a): ?>
                            <div class="as-card">
                                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;">
                                    <span class="as-topic"><?= htmlspecialchars($a['topic']) ?></span>
                                    <span class="badge badge-<?= $a['status'] === 'published' ? 'approved' : 'pending' ?>">
                                        <?= ucfirst($a['status']) ?>
                                    </span>
                                </div>
                                <div class="as-title"><?= htmlspecialchars($a['title']) ?></div>
                                <?php if (!empty($a['instructions'])): ?>
                                    <div class="as-sub"><?= htmlspecialchars(mb_strimwidth($a['instructions'], 0, 110, '…')) ?></div>
                                <?php endif; ?>

                                <div class="as-meta">
                                    <span>
                                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>
                                        <?= (int)$a['question_count'] ?> question<?= (int)$a['question_count'] === 1 ? '' : 's' ?>
                                    </span>
                                    <span><?= (int)$a['total_points'] ?> pts</span>
                                    <?php if ($a['time_limit_minutes']): ?>
                                        <span>
                                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7v5l3 3" /></svg>
                                            <?= (int)$a['time_limit_minutes'] ?> min
                                        </span>
                                    <?php endif; ?>
                                    <?php if ((int)$a['submissions'] > 0): ?>
                                        <span style="color:var(--success);font-weight:600;">
                                            <?= (int)$a['submissions'] ?> submitted<?= (int)$a['mentees_done'] > 0 ? ' by ' . (int)$a['mentees_done'] . ' mentee' . ((int)$a['mentees_done'] === 1 ? '' : 's') : '' ?> · avg <?= (int)$a['avg_percent'] ?>%
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="as-actions">
                                    <?php if ((int)$a['submissions'] > 0): ?>
                                        <a href="<?= htmlspecialchars(url('assessment-results')) ?>?id=<?= (int)$a['assessment_id'] ?>" class="btn btn-ghost">Results</a>
                                    <?php endif; ?>
                                    <a href="<?= htmlspecialchars(url('assessment-create')) ?>?id=<?= (int)$a['assessment_id'] ?>" class="btn btn-ghost">Edit</a>
                                    <form method="POST" action="<?= htmlspecialchars(url('assessment-delete')) ?>" style="flex:1;display:flex;"
                                        data-pc-tone="danger" data-pc-ok="Delete"
                                        data-pc-confirm="<?= htmlspecialchars(
                                                                (int)$a['submissions'] > 0
                                                                    ? 'Delete this assessment, its questions and ' . (int)$a['submissions'] . ' submitted result'
                                                                        . ((int)$a['submissions'] === 1 ? '' : 's') . "?\n"
                                                                        . 'The mentees who took it will no longer see their score.'
                                                                    : 'Delete this assessment and all its questions?',
                                                                ENT_QUOTES
                                                            ) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="assessment_id" value="<?= (int)$a['assessment_id'] ?>">
                                        <button type="submit" class="btn btn-ghost" style="flex:1;justify-content:center;font-size:12.5px;color:var(--danger);">Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php else: /* ── Mentee view ── */ ?>

                <?php if (empty($rows)): ?>
                    <div class="pcard">
                        <div class="prow-empty">
                            <p style="margin:0 0 12px;">No assessments yet. Once a mentor you've had a session with publishes one, it will show up here.</p>
                            <a href="<?= htmlspecialchars(url('mentee-find')) ?>" class="btn btn-primary btn-sm">Find a mentor</a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php if (!empty($todo)): ?>
                        <div class="as-section-title">To take (<?= count($todo) ?>)</div>
                        <div class="as-grid">
                            <?php foreach ($todo as $a): ?>
                                <div class="as-card">
                                    <span class="as-topic"><?= htmlspecialchars($a['topic']) ?></span>
                                    <div class="as-title"><?= htmlspecialchars($a['title']) ?></div>
                                    <div class="as-sub">Mentor: <?= htmlspecialchars(trim($a['firstname'] . ' ' . $a['lastname'])) ?></div>
                                    <div class="as-meta">
                                        <span><?= (int)$a['question_count'] ?> questions</span>
                                        <span><?= (int)$a['total_points'] ?> pts</span>
                                        <?php if ($a['time_limit_minutes']): ?>
                                            <span>
                                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7v5l3 3" /></svg>
                                                <?= (int)$a['time_limit_minutes'] ?> min
                                            </span>
                                        <?php endif; ?>
                                        <?php if (($a['attempt_status'] ?? '') === 'in_progress'): ?>
                                            <span style="color:var(--warning);font-weight:600;">In progress</span>
                                        <?php endif; ?>
                                        <?php if ((int)$a['my_attempts'] > 1): ?>
                                            <span><?= (int)$a['my_attempts'] - 1 ?> earlier attempt<?= (int)$a['my_attempts'] === 2 ? '' : 's' ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="as-actions">
                                        <a href="<?= htmlspecialchars(url('assessment-take')) ?>?id=<?= (int)$a['assessment_id'] ?>" class="btn btn-primary">
                                            <?= ($a['attempt_status'] ?? '') === 'in_progress' ? 'Continue' : 'Start Assessment' ?>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($done)): ?>
                        <div class="as-section-title">Completed (<?= count($done) ?>)</div>
                        <div class="as-grid">
                            <?php foreach ($done as $a):
                                $pct = (int)$a['scored_out_of'] > 0 ? round($a['score'] / $a['scored_out_of'] * 100) : 0;
                            ?>
                                <div class="as-card">
                                    <span class="as-topic"><?= htmlspecialchars($a['topic']) ?></span>
                                    <div class="as-title"><?= htmlspecialchars($a['title']) ?></div>
                                    <div class="as-score">
                                        <b><?= $pct ?>%</b>
                                        <span style="font-size:12.5px;color:var(--gray-500);"><?= (int)$a['score'] ?> / <?= (int)$a['scored_out_of'] ?> points</span>
                                    </div>
                                    <div class="as-meta">
                                        <span>Mentor: <?= htmlspecialchars(trim($a['firstname'] . ' ' . $a['lastname'])) ?></span>
                                        <span>Submitted <?= date('M j, Y', strtotime($a['submitted_at'])) ?></span>
                                        <?php if ((int)$a['my_attempts'] > 1): ?>
                                            <span><?= (int)$a['my_attempts'] ?> attempts</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="as-actions">
                                        <!-- Reviewing is all there is once it is
                                             submitted: one attempt per mentee. -->
                                        <a href="<?= htmlspecialchars(url('assessment-take')) ?>?id=<?= (int)$a['assessment_id'] ?>" class="btn btn-primary">Review answers</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        }

        function toggleProfileMenu() {
            const m = document.getElementById('profileMenu');
            if (m) m.classList.toggle('open');
        }
        document.addEventListener('click', function(e) {
            const m = document.getElementById('profileMenu');
            if (m && !e.target.closest('#profileMenu') && !e.target.closest('button[onclick="toggleProfileMenu()"]')) {
                m.classList.remove('open');
            }
        });
    </script>
</body>

</html>
