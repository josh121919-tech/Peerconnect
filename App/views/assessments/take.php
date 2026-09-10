<?php
// Take an assessment (mentee). Answers save as you go, so a refresh or a
// closed tab keeps progress; submitting scores the attempt server-side.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mentee') {
    header("Location: " . url('welcomepage'));
    exit;
}

$mentee_id     = (int)$_SESSION['user_id'];
$assessment_id = (int)($_GET['id'] ?? 0);

// The mentee must actually have a session history with this mentor.
$stmt = $con->prepare("
    SELECT a.*, u.firstname, u.lastname, u.user_id AS mentor_user_id
    FROM assessments a
    JOIN users u ON u.user_id = a.mentor_id
    WHERE a.assessment_id = ?
      AND a.status = 'published'
      AND EXISTS (
          SELECT 1 FROM session_requests sr
           WHERE sr.mentor_id = a.mentor_id AND sr.mentee_id = ?
             AND sr.status IN ('approved','completed')
      )
");
$stmt->bind_param("ii", $assessment_id, $mentee_id);
$stmt->execute();
$assessment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$assessment) {
    pc_flash('error', 'That assessment is not available to you.');
    header("Location: " . url('assessments'));
    exit;
}

// Questions + options
$questions = [];
$qres = $con->query("SELECT * FROM assessment_questions WHERE assessment_id = $assessment_id ORDER BY question_order ASC");
while ($q = $qres->fetch_assoc()) {
    $q['options'] = $con->query(
        "SELECT option_id, option_text FROM assessment_options WHERE question_id = " . (int)$q['question_id'] . " ORDER BY option_order ASC"
    )->fetch_all(MYSQLI_ASSOC);
    $questions[] = $q;
}

if (empty($questions)) {
    pc_flash('error', 'That assessment has no questions yet.');
    header("Location: " . url('assessments'));
    exit;
}

$total_points = array_sum(array_column($questions, 'points'));

// Attempt: resume, or start a fresh one.
$att = $con->prepare("SELECT * FROM assessment_attempts WHERE assessment_id = ? AND mentee_id = ?");
$att->bind_param("ii", $assessment_id, $mentee_id);
$att->execute();
$attempt = $att->get_result()->fetch_assoc();
$att->close();

if (!$attempt) {
    $ins = $con->prepare("INSERT INTO assessment_attempts (assessment_id, mentee_id, total_points) VALUES (?, ?, ?)");
    $ins->bind_param("iii", $assessment_id, $mentee_id, $total_points);
    $ins->execute();
    $attempt_id = (int)$con->insert_id;
    $ins->close();
    $attempt = [
        'attempt_id' => $attempt_id,
        'status' => 'in_progress',
        'started_at' => date('Y-m-d H:i:s'),
        'score' => 0,
        'total_points' => $total_points,
        'submitted_at' => null,
    ];
}

$attempt_id = (int)$attempt['attempt_id'];
$is_review  = $attempt['status'] === 'submitted';

// Saved answers, keyed by question
$saved = [];
$ares = $con->query("SELECT * FROM assessment_answers WHERE attempt_id = $attempt_id");
while ($r = $ares->fetch_assoc()) {
    $saved[(int)$r['question_id']] = $r;
}

// Remaining time, computed from the server's start timestamp so the clock
// can't be extended by reloading.
// Elapsed time is measured by the database, not by PHP: started_at is written
// by MySQL's clock, and PHP's configured timezone here is not the same one, so
// mixing time() with strtotime() on that column skews the clock by hours.
$seconds_left = null;
if (!$is_review && !empty($assessment['time_limit_minutes'])) {
    $el = $con->prepare("SELECT TIMESTAMPDIFF(SECOND, started_at, NOW()) e FROM assessment_attempts WHERE attempt_id = ?");
    $el->bind_param("i", $attempt_id);
    $el->execute();
    $elapsed = (int)($el->get_result()->fetch_assoc()['e'] ?? 0);
    $el->close();
    $seconds_left = max(0, ((int)$assessment['time_limit_minutes'] * 60) - $elapsed);
}

// For review mode, the correct answers so results can be shown.
$correct_map = [];
if ($is_review) {
    $cres = $con->query("
        SELECT q.question_id, q.correct_text, o.option_id, o.option_text, o.is_correct
        FROM assessment_questions q
        LEFT JOIN assessment_options o ON o.question_id = q.question_id
        WHERE q.assessment_id = $assessment_id
    ");
    while ($r = $cres->fetch_assoc()) {
        $qid = (int)$r['question_id'];
        if (!isset($correct_map[$qid])) {
            $correct_map[$qid] = ['text' => $r['correct_text'], 'option_id' => null, 'option_text' => null];
        }
        if ((int)$r['is_correct'] === 1) {
            $correct_map[$qid]['option_id']   = (int)$r['option_id'];
            $correct_map[$qid]['option_text'] = $r['option_text'];
        }
    }
}

$mentor_name = trim($assessment['firstname'] . ' ' . $assessment['lastname']);
$active_page = 'assessments';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($assessment['title']) ?> — PeerConnect</title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <style>
        .tk-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 300px;
            gap: 18px;
            align-items: start;
        }

        .tk-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .tk-timer {
            display: flex;
            align-items: center;
            gap: 14px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 12px 16px;
        }

        .tk-timer svg {
            width: 22px;
            height: 22px;
            color: var(--mint);
        }

        .tk-timer-val {
            font-size: 21px;
            font-weight: 700;
            color: var(--forest);
            line-height: 1;
            font-variant-numeric: tabular-nums;
        }

        .tk-timer.low .tk-timer-val {
            color: var(--danger);
        }

        .tk-instructions {
            display: flex;
            gap: 12px;
            background: var(--mint-faint);
            border: 1px solid var(--mint-soft);
            border-radius: var(--radius-lg);
            padding: 16px 18px;
            margin-bottom: 16px;
        }

        .tk-instructions svg {
            width: 20px;
            height: 20px;
            color: var(--mint);
            flex-shrink: 0;
        }

        .tk-qcard {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 24px;
            margin-bottom: 16px;
        }

        .tk-qhead {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .tk-qcount {
            font-size: 12.5px;
            color: var(--gray-500);
        }

        .tk-points {
            font-size: 11.5px;
            font-weight: 700;
            color: var(--info);
            background: var(--info-bg);
            border-radius: 999px;
            padding: 4px 11px;
        }

        .tk-qtext {
            font-size: 16px;
            font-weight: 700;
            color: var(--forest);
            line-height: 1.45;
            margin-bottom: 18px;
        }

        .tk-opt {
            display: flex;
            align-items: center;
            gap: 13px;
            width: 100%;
            text-align: left;
            padding: 15px 18px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            background: var(--surface);
            font-size: 13.5px;
            font-family: inherit;
            color: var(--gray-800);
            cursor: pointer;
            margin-bottom: 10px;
        }

        .tk-opt:hover {
            border-color: var(--mint-soft);
            background: var(--mint-faint);
        }

        .tk-opt.selected {
            border-color: var(--mint);
            background: var(--mint-faint);
        }

        .tk-opt.correct {
            border-color: var(--success);
            background: var(--success-bg);
        }

        .tk-opt.wrong {
            border-color: var(--danger);
            background: var(--danger-bg);
        }

        .tk-radio {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 2px solid var(--gray-300);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .tk-opt.selected .tk-radio,
        .tk-opt.correct .tk-radio {
            border-color: currentColor;
            background: currentColor;
        }

        .tk-opt.selected .tk-radio { color: var(--mint); }
        .tk-opt.correct .tk-radio  { color: var(--success); }
        .tk-opt.wrong .tk-radio    { color: var(--danger); border-color: var(--danger); background: var(--danger); }

        .tk-radio svg {
            width: 12px;
            height: 12px;
            color: #fff;
            display: none;
        }

        .tk-opt.selected .tk-radio svg,
        .tk-opt.correct .tk-radio svg,
        .tk-opt.wrong .tk-radio svg {
            display: block;
        }

        .tk-hint {
            display: flex;
            gap: 10px;
            background: var(--info-bg);
            border-radius: var(--radius);
            padding: 13px 16px;
            font-size: 12.5px;
            color: var(--gray-600);
            margin-top: 16px;
        }

        .tk-hint svg {
            width: 16px;
            height: 16px;
            color: var(--info);
            flex-shrink: 0;
        }

        .tk-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 14px 18px;
        }

        .tk-side {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 18px 20px;
            margin-bottom: 14px;
        }

        .tk-side-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--forest);
            margin-bottom: 14px;
        }

        .tk-progress-track {
            height: 8px;
            border-radius: 999px;
            background: var(--gray-100);
            overflow: hidden;
            flex: 1;
        }

        .tk-progress-fill {
            height: 100%;
            background: var(--mint);
            border-radius: 999px;
            transition: width .25s;
        }

        .tk-qnav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
        }

        .tk-qnav-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: 1.5px solid var(--border);
            background: var(--surface);
            color: var(--gray-600);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
        }

        .tk-qnav-btn.current {
            border-color: var(--mint);
            background: var(--mint);
            color: #fff;
        }

        .tk-qnav-btn.answered {
            border-color: var(--mint-soft);
            background: var(--mint-faint);
            color: var(--forest);
        }

        .tk-qnav-btn.flagged {
            border-color: var(--warning);
            color: var(--warning);
        }

        .tk-legend {
            display: flex;
            flex-direction: column;
            gap: 8px;
            font-size: 12.5px;
            color: var(--gray-500);
        }

        .tk-legend span {
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .tk-dot {
            width: 11px;
            height: 11px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .tk-help {
            background: var(--mint-faint);
            border-color: var(--mint-soft);
        }

        .tk-result {
            text-align: center;
            padding: 8px 0 4px;
        }

        .tk-result b {
            display: block;
            font-size: 34px;
            font-weight: 700;
            color: var(--forest);
            line-height: 1;
        }

        @media (max-width: 1100px) {
            .tk-layout {
                grid-template-columns: minmax(0, 1fr);
            }
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

            <div class="tk-topbar">
                <div>
                    <h1 style="font-size:24px;font-weight:700;color:var(--forest);margin:0 0 6px;">
                        Assessment: <?= htmlspecialchars($assessment['title']) ?>
                    </h1>
                    <p style="font-size:13px;color:var(--gray-500);margin:0;">
                        Mentor: <a href="<?= htmlspecialchars(url('mentee-view-mentor')) ?>?id=<?= (int)$assessment['mentor_user_id'] ?>" style="color:var(--mint);font-weight:600;"><?= htmlspecialchars($mentor_name) ?></a>
                        <?php if ($assessment['time_limit_minutes']): ?>
                            &nbsp;•&nbsp; Time Limit: <?= (int)$assessment['time_limit_minutes'] ?> minutes
                        <?php endif; ?>
                    </p>
                </div>

                <?php if (!$is_review): ?>
                    <div class="tk-timer" id="tkTimer">
                        <?php if ($seconds_left !== null): ?>
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7v5l3 3" /></svg>
                            <div>
                                <div style="font-size:11.5px;color:var(--gray-500);">Time Remaining</div>
                                <div class="tk-timer-val" id="tkTimerVal">--:--</div>
                            </div>
                        <?php endif; ?>
                        <button type="button" class="btn btn-primary" onclick="submitAssessment(false)">Submit Assessment</button>
                    </div>
                <?php else: ?>
                    <div class="tk-timer">
                        <div>
                            <div style="font-size:11.5px;color:var(--gray-500);">Your score</div>
                            <div class="tk-timer-val"><?= (int)$attempt['score'] ?> / <?= (int)$attempt['total_points'] ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($assessment['instructions'])): ?>
                <div class="tk-instructions">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 16v-4M12 8h.01" /></svg>
                    <div>
                        <div style="font-size:13.5px;font-weight:700;color:var(--forest);margin-bottom:4px;">Instructions</div>
                        <div style="font-size:13px;color:var(--gray-600);line-height:1.6;"><?= nl2br(htmlspecialchars($assessment['instructions'])) ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="tk-layout">
                <div>
                    <div id="tkQuestions"></div>

                    <?php if (!$is_review): ?>
                        <div class="tk-nav">
                            <button type="button" class="btn btn-ghost btn-sm" id="tkFlag" onclick="toggleFlag()">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 21V4h14l-3 4 3 4H5" /></svg>
                                Flag Question
                            </button>
                            <div style="display:flex;gap:10px;">
                                <button type="button" class="btn btn-ghost" id="tkPrev" onclick="goQuestion(currentQ - 1)">Previous</button>
                                <button type="button" class="btn btn-primary" id="tkNext" onclick="goNext()">
                                    <span id="tkNextLabel">Next Question</span>
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m0 0-5-5m5 5-5 5" /></svg>
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="tk-nav">
                            <span style="font-size:13px;color:var(--gray-500);">Reviewing your submitted answers.</span>
                            <div style="display:flex;gap:10px;">
                                <button type="button" class="btn btn-ghost" onclick="goQuestion(currentQ - 1)">Previous</button>
                                <button type="button" class="btn btn-primary" onclick="goQuestion(currentQ + 1)">Next</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <?php if ($is_review): ?>
                        <div class="tk-side">
                            <div class="tk-side-title">Result</div>
                            <div class="tk-result">
                                <b><?= (int)$attempt['total_points'] > 0 ? round($attempt['score'] / $attempt['total_points'] * 100) : 0 ?>%</b>
                                <span style="font-size:12.5px;color:var(--gray-500);">
                                    <?= (int)$attempt['score'] ?> of <?= (int)$attempt['total_points'] ?> points
                                </span>
                            </div>
                            <div class="ac-side-row" style="margin-top:14px;font-size:12.5px;color:var(--gray-500);text-align:center;">
                                Submitted <?= date('M j, Y g:i A', strtotime($attempt['submitted_at'])) ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="tk-side">
                            <div class="tk-side-title">Assessment Progress</div>
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
                                <div class="tk-progress-track"><div class="tk-progress-fill" id="tkProgressFill" style="width:0%;"></div></div>
                                <span style="font-size:13px;font-weight:700;color:var(--mint);" id="tkProgressPct">0%</span>
                            </div>
                            <div class="ac-side-row" style="display:flex;justify-content:space-between;font-size:13px;color:var(--gray-600);padding:6px 0;">
                                <span>Answered</span><b id="tkAnswered" style="color:var(--forest);">0 of <?= count($questions) ?></b>
                            </div>
                            <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--gray-600);padding:6px 0;">
                                <span>Total Points</span><b style="color:var(--forest);"><?= (int)$total_points ?> points</b>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="tk-side">
                        <div class="tk-side-title">Question Navigation</div>
                        <div class="tk-qnav" id="tkQNav"></div>
                        <div class="tk-legend">
                            <span><i class="tk-dot" style="background:var(--mint);"></i> Answered</span>
                            <span><i class="tk-dot" style="background:var(--gray-200);"></i> Not Answered</span>
                            <span><i class="tk-dot" style="background:var(--warning);"></i> Flagged</span>
                        </div>
                    </div>

                    <div class="tk-side tk-help">
                        <div style="display:flex;align-items:center;gap:11px;margin-bottom:12px;">
                            <span style="width:36px;height:36px;border-radius:50%;background:var(--mint);color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7A2.5 2.5 0 0 1 17.5 16H12l-5 4v-4h-.5A2.5 2.5 0 0 1 4 13.5v-7Z" /></svg>
                            </span>
                            <div>
                                <div style="font-size:13.5px;font-weight:700;color:var(--forest);">Need Help?</div>
                                <div style="font-size:12.5px;color:var(--gray-500);line-height:1.45;">If something is unclear, contact your mentor.</div>
                            </div>
                        </div>
                        <a href="<?= htmlspecialchars(url('messages')) ?>?chat=<?= (int)$assessment['mentor_user_id'] ?>" class="btn btn-primary" style="width:100%;justify-content:center;font-size:13px;">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7A2.5 2.5 0 0 1 17.5 16H12l-5 4v-4h-.5A2.5 2.5 0 0 1 4 13.5v-7Z" /></svg>
                            Message Mentor
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        const QUESTIONS    = <?= json_encode($questions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const SAVED        = <?= json_encode($saved, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const CORRECT      = <?= json_encode($correct_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const IS_REVIEW    = <?= $is_review ? 'true' : 'false' ?>;
        const ATTEMPT_ID   = <?= (int)$attempt_id ?>;
        const TK_CSRF      = <?= json_encode(csrf_token()) ?>;
        const ROUTE_ANSWER = <?= json_encode(url('assessment-answer')) ?>;
        const ROUTE_SUBMIT = <?= json_encode(url('assessment-submit')) ?>;
        const RESULT_URL   = <?= json_encode(url('assessment-take') . '?id=' . $assessment_id) ?>;
        let secondsLeft    = <?= $seconds_left === null ? 'null' : (int)$seconds_left ?>;

        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
        function toggleProfileMenu() {
            const m = document.getElementById('profileMenu');
            if (m) m.classList.toggle('open');
        }
        document.addEventListener('click', function(e) {
            const m = document.getElementById('profileMenu');
            if (m && !e.target.closest('#profileMenu') && !e.target.closest('button[onclick="toggleProfileMenu()"]')) m.classList.remove('open');
        });

        // Local answer state, seeded from whatever was already saved.
        const state = {};
        QUESTIONS.forEach(q => {
            const s = SAVED[q.question_id];
            state[q.question_id] = {
                option_id: s && s.selected_option_id ? Number(s.selected_option_id) : null,
                text: s ? (s.answer_text || '') : '',
                flagged: s ? Number(s.is_flagged) === 1 : false,
                is_correct: s ? Number(s.is_correct) === 1 : false
            };
        });

        let currentQ = 0;

        function renderQuestion() {
            const q = QUESTIONS[currentQ];
            const st = state[q.question_id];
            const box = document.getElementById('tkQuestions');
            box.innerHTML = '';

            const card = document.createElement('div');
            card.className = 'tk-qcard';

            const head = document.createElement('div');
            head.className = 'tk-qhead';
            head.innerHTML = '<span class="tk-qcount">Question ' + (currentQ + 1) + ' of ' + QUESTIONS.length + '</span>' +
                '<span class="tk-points">' + q.points + ' point' + (Number(q.points) === 1 ? '' : 's') + '</span>';
            card.appendChild(head);

            const text = document.createElement('div');
            text.className = 'tk-qtext';
            text.textContent = q.question_text;
            card.appendChild(text);

            if (q.question_type === 'short_answer') {
                const input = document.createElement('input');
                input.className = 'ac-input';
                input.style.cssText = 'width:100%;font-size:13.5px;padding:12px 14px;border:1.5px solid var(--border);border-radius:var(--radius);font-family:inherit;';
                input.placeholder = 'Type your answer';
                input.value = st.text || '';
                input.disabled = IS_REVIEW;
                input.addEventListener('input', () => {
                    st.text = input.value;
                    renderNav();
                });
                input.addEventListener('change', () => {
                    st.text = input.value;
                    saveAnswer(q.question_id, null, input.value);
                    renderNav();
                });
                card.appendChild(input);
                if (IS_REVIEW && CORRECT[q.question_id]) {
                    const ans = document.createElement('div');
                    ans.style.cssText = 'margin-top:10px;font-size:12.5px;color:var(--gray-600);';
                    ans.innerHTML = 'Accepted answer: <b style="color:var(--success);">' +
                        escapeHtml(CORRECT[q.question_id].text || '—') + '</b>';
                    card.appendChild(ans);
                }
            } else {
                (q.options || []).forEach((opt, i) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'tk-opt';
                    const optId = Number(opt.option_id);
                    const chosen = st.option_id === optId;

                    if (IS_REVIEW) {
                        const correctId = CORRECT[q.question_id] ? CORRECT[q.question_id].option_id : null;
                        if (optId === correctId) b.classList.add('correct');
                        else if (chosen) b.classList.add('wrong');
                        b.disabled = true;
                    } else if (chosen) {
                        b.classList.add('selected');
                    }

                    b.innerHTML = '<span class="tk-radio"><svg fill="none" stroke="currentColor" stroke-width="3.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></span>' +
                        '<span>' + String.fromCharCode(65 + i) + '. ' + escapeHtml(opt.option_text) + '</span>';

                    if (!IS_REVIEW) {
                        b.addEventListener('click', () => {
                            st.option_id = optId;
                            saveAnswer(q.question_id, optId, null);
                            renderQuestion();
                            renderNav();
                        });
                    }
                    card.appendChild(b);
                });
            }

            if (q.hint) {
                const hint = document.createElement('div');
                hint.className = 'tk-hint';
                hint.innerHTML = '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 18h6M10 21h4M12 3a6 6 0 0 1 4 10.5V15H8v-1.5A6 6 0 0 1 12 3Z"/></svg><span>' +
                    escapeHtml(q.hint) + '</span>';
                card.appendChild(hint);
            }

            box.appendChild(card);

            const prev = document.getElementById('tkPrev');
            const nextLbl = document.getElementById('tkNextLabel');
            if (prev) prev.disabled = currentQ === 0;
            // On the last question the primary button submits instead of advancing.
            if (nextLbl) nextLbl.textContent = currentQ === QUESTIONS.length - 1 ? 'Submit Assessment' : 'Next Question';

            const flagBtn = document.getElementById('tkFlag');
            if (flagBtn) {
                flagBtn.style.color = st.flagged ? 'var(--warning)' : '';
                flagBtn.lastChild.textContent = st.flagged ? ' Unflag Question' : ' Flag Question';
            }
        }

        function renderNav() {
            const nav = document.getElementById('tkQNav');
            nav.innerHTML = '';
            QUESTIONS.forEach((q, i) => {
                const st = state[q.question_id];
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'tk-qnav-btn';
                if (i === currentQ) b.classList.add('current');
                else if (st.flagged) b.classList.add('flagged');
                else if (st.option_id || (st.text && st.text.trim() !== '')) b.classList.add('answered');
                b.textContent = i + 1;
                b.addEventListener('click', () => goQuestion(i));
                nav.appendChild(b);
            });
            updateProgress();
        }

        function updateProgress() {
            if (IS_REVIEW) return;
            const answered = QUESTIONS.filter(q => {
                const st = state[q.question_id];
                return st.option_id || (st.text && st.text.trim() !== '');
            }).length;
            const pct = QUESTIONS.length ? Math.round(answered / QUESTIONS.length * 100) : 0;
            const fill = document.getElementById('tkProgressFill');
            if (fill) fill.style.width = pct + '%';
            const pctEl = document.getElementById('tkProgressPct');
            if (pctEl) pctEl.textContent = pct + '%';
            const ansEl = document.getElementById('tkAnswered');
            if (ansEl) ansEl.textContent = answered + ' of ' + QUESTIONS.length;
        }

        function goNext() {
            if (currentQ === QUESTIONS.length - 1) {
                submitAssessment();
                return;
            }
            goQuestion(currentQ + 1);
        }

        function goQuestion(i) {
            if (i < 0 || i >= QUESTIONS.length) return;
            currentQ = i;
            renderQuestion();
            renderNav();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function toggleFlag() {
            const q = QUESTIONS[currentQ];
            const st = state[q.question_id];
            st.flagged = !st.flagged;
            saveAnswer(q.question_id, st.option_id, st.text, st.flagged);
            renderQuestion();
            renderNav();
        }

        async function saveAnswer(questionId, optionId, text, flagged) {
            if (IS_REVIEW) return;
            const body = new URLSearchParams({
                csrf_token: TK_CSRF,
                attempt_id: ATTEMPT_ID,
                question_id: questionId,
                is_flagged: (flagged === undefined ? state[questionId].flagged : flagged) ? '1' : '0'
            });
            if (optionId) body.set('option_id', optionId);
            if (text != null) body.set('answer_text', text);
            try {
                await fetch(ROUTE_ANSWER, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                });
            } catch (e) { /* answers re-save on the next interaction */ }
        }

        async function submitAssessment(auto) {
            if (IS_REVIEW) return;
            const unanswered = QUESTIONS.filter(q => {
                const st = state[q.question_id];
                return Number(q.is_required) === 1 && !st.option_id && !(st.text && st.text.trim() !== '');
            }).length;

            if (!auto) {
                const msg = unanswered > 0
                    ? unanswered + ' required question(s) are still unanswered. Submit anyway?'
                    : 'Submit your assessment? You can only submit once.';
                if (!confirm(msg)) return;
            }

            try {
                const res = await fetch(ROUTE_SUBMIT, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'csrf_token=' + encodeURIComponent(TK_CSRF) + '&attempt_id=' + encodeURIComponent(ATTEMPT_ID)
                });
                const data = await res.json();
                if (data.success) {
                    window.location.href = RESULT_URL;
                } else {
                    alert(data.error || 'Could not submit. Please try again.');
                }
            } catch (e) {
                alert('Network error. Please try again.');
            }
        }

        function escapeHtml(s) {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
        }

        /* Timer — server supplies the remaining seconds so reloading can't
           reset it; hitting zero submits whatever has been answered. */
        if (secondsLeft !== null && !IS_REVIEW) {
            const valEl = document.getElementById('tkTimerVal');
            const wrapEl = document.getElementById('tkTimer');
            const tick = () => {
                const m = Math.floor(secondsLeft / 60);
                const s = secondsLeft % 60;
                valEl.textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
                wrapEl.classList.toggle('low', secondsLeft <= 60);
                if (secondsLeft <= 0) {
                    clearInterval(timer);
                    alert('Time is up. Your answers will be submitted.');
                    submitAssessment(true);
                    return;
                }
                secondsLeft--;
            };
            tick();
            const timer = setInterval(tick, 1000);
        }

        renderQuestion();
        renderNav();
    </script>
</body>

</html>
