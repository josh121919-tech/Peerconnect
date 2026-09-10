<?php
// Create / edit an assessment (mentor only).
// The 4 steps are client-side sections of one form — everything posts together
// to assessment-save, so a half-finished draft is never split across requests.
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
$assessment    = null;
$questions     = [];

if ($assessment_id) {
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

    $qres = $con->query("SELECT * FROM assessment_questions WHERE assessment_id = $assessment_id ORDER BY question_order ASC");
    while ($q = $qres->fetch_assoc()) {
        $opts = $con->query("SELECT * FROM assessment_options WHERE question_id = " . (int)$q['question_id'] . " ORDER BY option_order ASC")
            ->fetch_all(MYSQLI_ASSOC);
        $q['options'] = $opts;
        $questions[] = $q;
    }

    // Once a mentee has submitted, the questions are part of a graded record —
    // rewriting them would leave the stored answers pointing at questions that
    // no longer exist. Details stay editable; the question set locks.
    $submitted_count = (int)($con->query("
        SELECT COUNT(*) c FROM assessment_attempts
        WHERE assessment_id = $assessment_id AND status = 'submitted'
    ")->fetch_assoc()['c'] ?? 0);
}
$questions_locked = !empty($submitted_count);

// Topics come from the same academic taxonomy the rest of the app uses.
$topics = array_map(fn($c) => str_replace(' Club', '', $c), PC_CLUBS);


$active_page = 'assessments';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $assessment ? 'Edit' : 'Create' ?> Assessment — PeerConnect</title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <style>
        .ac-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 300px;
            gap: 18px;
            align-items: start;
        }

        /* Stepper */
        .ac-steps {
            display: flex;
            align-items: flex-start;
            margin-bottom: 22px;
        }

        .ac-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
            background: none;
            border: none;
            cursor: pointer;
            font-family: inherit;
            padding: 0;
        }

        .ac-step-dot {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            background: var(--gray-100);
            color: var(--gray-500);
            border: 2px solid transparent;
        }

        .ac-step.active .ac-step-dot {
            background: var(--mint);
            color: #fff;
        }

        .ac-step.done .ac-step-dot {
            background: var(--mint-faint);
            color: var(--forest);
            border-color: var(--mint-soft);
        }

        .ac-step-label {
            font-size: 12.5px;
            color: var(--gray-500);
        }

        .ac-step.active .ac-step-label {
            color: var(--mint);
            font-weight: 700;
        }

        .ac-step-line {
            flex: 1;
            height: 2px;
            background: var(--border);
            margin: 17px 6px 0;
        }

        .ac-panel {
            display: none;
        }

        .ac-panel.active {
            display: block;
        }

        .ac-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 22px;
            margin-bottom: 16px;
        }

        .ac-card-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--forest);
            margin-bottom: 16px;
        }

        .ac-field-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .ac-label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 6px;
        }

        .ac-input,
        .ac-select,
        .ac-textarea {
            width: 100%;
            font-size: 13.5px;
            font-family: inherit;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--surface);
            color: var(--gray-900);
        }

        .ac-input:focus,
        .ac-select:focus,
        .ac-textarea:focus {
            outline: none;
            border-color: var(--mint);
        }

        .ac-textarea {
            resize: vertical;
            min-height: 84px;
        }

        .ac-help {
            font-size: 11.5px;
            color: var(--gray-400);
            margin-top: 5px;
        }

        /* Question builder */
        .q-card {
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 12px;
            background: var(--surface);
        }

        .q-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }

        .q-num {
            width: 26px;
            height: 26px;
            border-radius: var(--radius-sm);
            background: var(--forest);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .q-type-select {
            font-size: 12.5px;
            padding: 5px 9px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--mint);
            font-weight: 600;
            background: var(--surface);
        }

        .q-tools {
            margin-left: auto;
            display: flex;
            gap: 6px;
        }

        .q-tool {
            width: 30px;
            height: 30px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--surface);
            color: var(--gray-400);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            padding: 0;
        }

        .q-tool:hover {
            background: var(--mint-faint);
            color: var(--forest);
        }

        .q-tool.danger:hover {
            background: var(--danger-bg);
            color: var(--danger);
        }

        .q-opt {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            margin-bottom: 7px;
        }

        .q-opt.correct {
            border-color: var(--mint);
            background: var(--mint-faint);
        }

        .q-opt input[type="text"] {
            flex: 1;
            border: none;
            background: transparent;
            font-size: 13px;
            font-family: inherit;
            outline: none;
            color: var(--gray-900);
        }

        .q-opt-radio {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
            accent-color: var(--mint);
        }

        .q-foot {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border);
        }

        .q-points {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 12.5px;
            color: var(--gray-600);
        }

        .q-points input {
            width: 62px;
            font-size: 13px;
            padding: 5px 8px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: inherit;
        }

        /* Toggle */
        .q-toggle {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            font-size: 12.5px;
            color: var(--gray-600);
            cursor: pointer;
        }

        .q-toggle input {
            display: none;
        }

        .q-toggle-track {
            width: 34px;
            height: 19px;
            border-radius: 999px;
            background: var(--gray-300);
            position: relative;
            transition: background .15s;
            flex-shrink: 0;
        }

        .q-toggle-track::after {
            content: '';
            position: absolute;
            top: 2.5px;
            left: 2.5px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #fff;
            transition: transform .15s;
        }

        .q-toggle input:checked + .q-toggle-track {
            background: var(--mint);
        }

        .q-toggle input:checked + .q-toggle-track::after {
            transform: translateX(15px);
        }

        /* Sidebar */
        .ac-side {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 18px 20px;
            margin-bottom: 14px;
        }

        .ac-side-title {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 14px;
            font-weight: 700;
            color: var(--forest);
            margin-bottom: 14px;
        }

        .ac-side-title svg {
            width: 17px;
            height: 17px;
            color: var(--mint);
        }

        .ac-side-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 8px 0;
            font-size: 13px;
            color: var(--gray-600);
            border-bottom: 1px solid var(--border);
        }

        .ac-side-row:last-child {
            border-bottom: none;
        }

        .ac-side-row b {
            color: var(--forest);
        }

        .ac-tips {
            background: var(--mint-faint);
            border-color: var(--mint-soft);
        }

        .ac-tips ul {
            margin: 0;
            padding-left: 18px;
            font-size: 12.5px;
            color: var(--gray-600);
            line-height: 1.75;
        }

        .ac-review-q {
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }

        .ac-review-q:last-child {
            border-bottom: none;
        }

        .ac-flash {
            border-radius: var(--radius);
            padding: 11px 16px;
            font-size: 13px;
            margin-bottom: 16px;
            background: var(--danger-bg);
            color: var(--danger);
        }

        .ac-note {
            border-radius: var(--radius);
            padding: 11px 16px;
            font-size: 12.5px;
            line-height: 1.55;
            margin-bottom: 14px;
            background: var(--mint-faint);
            color: var(--forest);
            border: 1px solid var(--mint-soft);
        }

        @media (max-width: 1100px) {
            .ac-layout {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        @media (max-width: 760px) {
            .ac-field-grid {
                grid-template-columns: 1fr;
            }

            .ac-step-label {
                display: none;
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

            <div class="page-hd">
                <h1><?= $assessment ? 'Edit' : 'Create' ?> Assessment</h1>
                <p><?= $assessment
                        ? 'Update this assessment’s details' . ($questions_locked ? ' — its questions are locked.' : ' and questions.')
                        : 'Create questions to assess your mentee’s understanding of the topic.' ?></p>
            </div>


            <div class="ac-steps">
                <button type="button" class="ac-step active" data-step="1" onclick="goStep(1)">
                    <span class="ac-step-dot">1</span><span class="ac-step-label">Details</span>
                </button>
                <div class="ac-step-line"></div>
                <button type="button" class="ac-step" data-step="2" onclick="goStep(2)">
                    <span class="ac-step-dot">2</span><span class="ac-step-label">Questions</span>
                </button>
                <div class="ac-step-line"></div>
                <button type="button" class="ac-step" data-step="3" onclick="goStep(3)">
                    <span class="ac-step-dot">3</span><span class="ac-step-label">Settings</span>
                </button>
                <div class="ac-step-line"></div>
                <button type="button" class="ac-step" data-step="4" onclick="goStep(4)">
                    <span class="ac-step-dot">4</span><span class="ac-step-label">Review</span>
                </button>
            </div>

            <form method="POST" action="<?= htmlspecialchars(url('assessment-save')) ?>" id="acForm">
                <?= csrf_field() ?>
                <input type="hidden" name="assessment_id" value="<?= (int)$assessment_id ?>">
                <input type="hidden" name="publish" id="acPublish" value="<?= $assessment && $assessment['status'] === 'published' ? '1' : '0' ?>">
                <input type="hidden" name="questions_json" id="acQuestionsJson">

                <div class="ac-layout">
                    <div>
                        <!-- Step 1 — Details -->
                        <section class="ac-panel active" data-panel="1">
                            <div class="ac-card">
                                <div class="ac-card-title">Assessment Details</div>
                                <div class="ac-field-grid">
                                    <div>
                                        <label class="ac-label" for="acTitle">Assessment Title *</label>
                                        <input class="ac-input" id="acTitle" name="title" required maxlength="200"
                                            value="<?= htmlspecialchars($assessment['title'] ?? '') ?>"
                                            placeholder="e.g. Study Strategies &amp; Time Management">
                                        <div class="ac-help">Give your assessment a clear and descriptive title.</div>
                                    </div>
                                    <div>
                                        <label class="ac-label" for="acTopic">Topic / Subject *</label>
                                        <select class="ac-select" id="acTopic" name="topic" required>
                                            <option value="">Select a topic</option>
                                            <?php foreach ($topics as $t): ?>
                                                <option value="<?= htmlspecialchars($t) ?>" <?= ($assessment['topic'] ?? '') === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                                            <?php endforeach; ?>
                                            <?php if (!empty($assessment['topic']) && !in_array($assessment['topic'], $topics, true)): ?>
                                                <option value="<?= htmlspecialchars($assessment['topic']) ?>" selected><?= htmlspecialchars($assessment['topic']) ?></option>
                                            <?php endif; ?>
                                        </select>
                                        <div class="ac-help">Select the topic this assessment is about.</div>
                                    </div>
                                </div>

                                <div class="ac-field-grid" style="margin-top:18px;">
                                    <div>
                                        <label class="ac-label" for="acInstructions">Instructions for Mentee</label>
                                        <textarea class="ac-textarea" id="acInstructions" name="instructions" maxlength="1000"
                                            placeholder="Read each question carefully and choose the best answer."><?= htmlspecialchars($assessment['instructions'] ?? '') ?></textarea>
                                        <div class="ac-help">These instructions will be shown to your mentee.</div>
                                    </div>
                                    <div>
                                        <label class="ac-label" for="acTimeLimit">Time Limit (Optional)</label>
                                        <div style="display:flex;gap:10px;">
                                            <input class="ac-input" id="acTimeLimit" name="time_limit" type="number" min="1" max="480"
                                                value="<?= htmlspecialchars((string)($assessment['time_limit_minutes'] ?? '')) ?>" placeholder="30">
                                            <select class="ac-select" style="width:140px;" disabled><option>Minutes</option></select>
                                        </div>
                                        <div class="ac-help">Set a time limit for completing the assessment.</div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Step 2 — Questions -->
                        <section class="ac-panel" data-panel="2">
                            <div class="ac-card">
                                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px;">
                                    <div>
                                        <div class="ac-card-title" style="margin-bottom:2px;">Questions</div>
                                        <div class="ac-help" style="margin:0;">
                                            <?= $questions_locked
                                                ? 'Locked — ' . $submitted_count . ' mentee' . ($submitted_count === 1 ? ' has' : 's have') . ' already submitted this assessment.'
                                                : "Add questions to assess your mentee's knowledge." ?>
                                        </div>
                                    </div>
                                    <?php if (!$questions_locked): ?>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="addQuestion()">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                                            Add Question
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <?php if ($questions_locked): ?>
                                    <div class="ac-note">
                                        Changing the questions now would break the answers already on record, so the question
                                        set is fixed. You can still edit the title, topic, instructions and time limit.
                                    </div>
                                <?php endif; ?>
                                <div id="qList"></div>
                                <div id="qEmpty" class="prow-empty" style="display:none;">
                                    No questions yet — add your first one to get started.
                                </div>
                            </div>
                        </section>

                        <!-- Step 3 — Settings -->
                        <section class="ac-panel" data-panel="3">
                            <div class="ac-card">
                                <div class="ac-card-title">Settings</div>
                                <div class="ac-side-row">
                                    <div>
                                        <div style="font-size:13.5px;color:var(--gray-800);font-weight:600;">Time limit</div>
                                        <div class="ac-help" style="margin:2px 0 0;">Leave the field on step 1 empty for an untimed assessment.</div>
                                    </div>
                                    <b id="setTime">—</b>
                                </div>
                                <div class="ac-side-row">
                                    <div>
                                        <div style="font-size:13.5px;color:var(--gray-800);font-weight:600;">Who can take this</div>
                                        <div class="ac-help" style="margin:2px 0 0;">Mentees you've had an approved or completed session with.</div>
                                    </div>
                                    <b id="setAudience">—</b>
                                </div>
                                <div class="ac-side-row">
                                    <div>
                                        <div style="font-size:13.5px;color:var(--gray-800);font-weight:600;">Attempts allowed</div>
                                        <div class="ac-help" style="margin:2px 0 0;">Each mentee can submit once.</div>
                                    </div>
                                    <b>1</b>
                                </div>
                                <div class="ac-side-row">
                                    <div>
                                        <div style="font-size:13.5px;color:var(--gray-800);font-weight:600;">Scoring</div>
                                        <div class="ac-help" style="margin:2px 0 0;">Auto-scored from the correct answers you marked.</div>
                                    </div>
                                    <b id="setPoints">0 pts</b>
                                </div>
                            </div>
                        </section>

                        <!-- Step 4 — Review -->
                        <section class="ac-panel" data-panel="4">
                            <div class="ac-card">
                                <div class="ac-card-title">Review</div>
                                <div id="reviewBody"></div>
                            </div>
                        </section>

                        <div style="display:flex;gap:10px;margin-bottom:20px;">
                            <button type="button" class="btn btn-ghost" id="acPrev" onclick="goStep(currentStep - 1)" style="display:none;">Back</button>
                            <button type="button" class="btn btn-ghost" id="acNext" onclick="goStep(currentStep + 1)">Next step</button>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div>
                        <div class="ac-side">
                            <div class="ac-side-title">
                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5" /></svg>
                                Assessment Summary
                            </div>
                            <div class="ac-side-row"><span>Questions</span><b id="sumQuestions">0</b></div>
                            <div class="ac-side-row"><span>Total Points</span><b id="sumPoints">0</b></div>
                            <div class="ac-side-row"><span>Time Limit</span><b id="sumTime">None</b></div>
                            <div class="ac-side-row"><span>Question Types</span><b id="sumTypes" style="font-size:11.5px;text-align:right;">—</b></div>
                            <div class="ac-side-row">
                                <span>Status</span>
                                <span class="badge badge-<?= ($assessment['status'] ?? 'draft') === 'published' ? 'approved' : 'pending' ?>">
                                    <?= ucfirst($assessment['status'] ?? 'draft') ?>
                                </span>
                            </div>
                        </div>

                        <div class="ac-side ac-tips">
                            <div class="ac-side-title">
                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 18h6M10 21h4M12 3a6 6 0 0 1 4 10.5V15H8v-1.5A6 6 0 0 1 12 3Z" /></svg>
                                Assessment Tips
                            </div>
                            <ul>
                                <li>Keep questions clear and specific.</li>
                                <li>Mix question types for better assessment.</li>
                                <li>Set an appropriate time limit.</li>
                                <li>Review before publishing.</li>
                            </ul>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-bottom:10px;"
                            onclick="document.getElementById('acPublish').value='1'">
                            <?= $assessment && $assessment['status'] === 'published' ? 'Save changes' : 'Publish assessment' ?>
                        </button>
                        <button type="submit" class="btn btn-ghost" style="width:100%;justify-content:center;"
                            onclick="document.getElementById('acPublish').value='0'">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4.5A1.5 1.5 0 016.5 3h11A1.5 1.5 0 0119 4.5V21l-7-4.5L5 21V4.5Z" /></svg>
                            Save as Draft
                        </button>
                    </div>
                </div>
            </form>
        </main>
    </div>

    <script>
        const EXISTING_QUESTIONS = <?= json_encode($questions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const QUESTIONS_LOCKED = <?= $questions_locked ? 'true' : 'false' ?>;

        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
        function toggleProfileMenu() {
            const m = document.getElementById('profileMenu');
            if (m) m.classList.toggle('open');
        }
        document.addEventListener('click', function(e) {
            const m = document.getElementById('profileMenu');
            if (m && !e.target.closest('#profileMenu') && !e.target.closest('button[onclick="toggleProfileMenu()"]')) m.classList.remove('open');
        });

        /* ── Step navigation ─────────────────────────────────────────────── */
        let currentStep = 1;
        function goStep(n) {
            if (n < 1 || n > 4) return;
            currentStep = n;
            document.querySelectorAll('.ac-panel').forEach(p =>
                p.classList.toggle('active', Number(p.dataset.panel) === n));
            document.querySelectorAll('.ac-step').forEach(s => {
                const sn = Number(s.dataset.step);
                s.classList.toggle('active', sn === n);
                s.classList.toggle('done', sn < n);
            });
            document.getElementById('acPrev').style.display = n === 1 ? 'none' : '';
            document.getElementById('acNext').style.display = n === 4 ? 'none' : '';
            if (n === 3 || n === 4) renderReview();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        /* ── Question builder ────────────────────────────────────────────── */
        let questions = [];
        let qSeq = 0;

        function blankQuestion(type) {
            qSeq++;
            const q = {
                uid: 'q' + qSeq,
                question_type: type || 'multiple_choice',
                question_text: '',
                hint: '',
                points: 1,
                is_required: 1,
                correct_text: '',
                options: []
            };
            if (q.question_type === 'multiple_choice') {
                q.options = [
                    { option_text: '', is_correct: 1 },
                    { option_text: '', is_correct: 0 },
                    { option_text: '', is_correct: 0 },
                    { option_text: '', is_correct: 0 }
                ];
            } else if (q.question_type === 'true_false') {
                q.options = [
                    { option_text: 'True', is_correct: 1 },
                    { option_text: 'False', is_correct: 0 }
                ];
            }
            return q;
        }

        function addQuestion() {
            questions.push(blankQuestion('multiple_choice'));
            renderQuestions();
        }

        function duplicateQuestion(i) {
            const copy = JSON.parse(JSON.stringify(questions[i]));
            qSeq++;
            copy.uid = 'q' + qSeq;
            questions.splice(i + 1, 0, copy);
            renderQuestions();
        }

        function deleteQuestion(i) {
            questions.splice(i, 1);
            renderQuestions();
        }

        function changeType(i, type) {
            const q = questions[i];
            q.question_type = type;
            if (type === 'true_false') {
                q.options = [
                    { option_text: 'True', is_correct: 1 },
                    { option_text: 'False', is_correct: 0 }
                ];
            } else if (type === 'multiple_choice') {
                if (!q.options.length || q.options.length < 2 || q.options[0].option_text === 'True') {
                    q.options = [
                        { option_text: '', is_correct: 1 },
                        { option_text: '', is_correct: 0 },
                        { option_text: '', is_correct: 0 },
                        { option_text: '', is_correct: 0 }
                    ];
                }
            } else {
                q.options = [];
            }
            renderQuestions();
        }

        function setCorrect(qi, oi) {
            questions[qi].options.forEach((o, i) => o.is_correct = i === oi ? 1 : 0);
            renderQuestions();
        }

        function addOption(qi) {
            questions[qi].options.push({ option_text: '', is_correct: 0 });
            renderQuestions();
        }

        function removeOption(qi, oi) {
            const q = questions[qi];
            if (q.options.length <= 2) return;
            const wasCorrect = q.options[oi].is_correct;
            q.options.splice(oi, 1);
            if (wasCorrect) q.options[0].is_correct = 1;
            renderQuestions();
        }

        const TYPE_LABEL = {
            multiple_choice: 'Multiple Choice',
            true_false: 'True / False',
            short_answer: 'Short Answer'
        };

        function renderQuestions() {
            const list = document.getElementById('qList');
            list.innerHTML = '';
            document.getElementById('qEmpty').style.display = questions.length ? 'none' : '';

            questions.forEach((q, i) => {
                const card = document.createElement('div');
                card.className = 'q-card';

                // Head
                const head = document.createElement('div');
                head.className = 'q-head';
                const num = document.createElement('span');
                num.className = 'q-num';
                num.textContent = i + 1;
                const typeSel = document.createElement('select');
                typeSel.className = 'q-type-select';
                Object.keys(TYPE_LABEL).forEach(t => {
                    const o = document.createElement('option');
                    o.value = t;
                    o.textContent = TYPE_LABEL[t];
                    if (q.question_type === t) o.selected = true;
                    typeSel.appendChild(o);
                });
                typeSel.addEventListener('change', e => changeType(i, e.target.value));

                const tools = document.createElement('div');
                tools.className = 'q-tools';
                tools.appendChild(toolBtn('Duplicate', 'M8 8V5a2 2 0 012-2h9a2 2 0 012 2v9a2 2 0 01-2 2h-3M5 8h9a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2v-9a2 2 0 012-2Z', () => duplicateQuestion(i)));
                tools.appendChild(toolBtn('Delete', 'M4 7h16M10 11v6M14 11v6M6 7l1 13a2 2 0 002 2h6a2 2 0 002-2l1-13M9 7V4h6v3', () => deleteQuestion(i), true));

                head.appendChild(num);
                head.appendChild(typeSel);
                head.appendChild(tools);
                card.appendChild(head);

                // Question text
                const qt = document.createElement('input');
                qt.className = 'ac-input';
                qt.placeholder = 'Type your question…';
                qt.value = q.question_text;
                qt.addEventListener('input', e => { q.question_text = e.target.value; updateSummary(); });
                card.appendChild(qt);

                // Options / answer
                if (q.question_type === 'short_answer') {
                    const lbl = document.createElement('div');
                    lbl.className = 'ac-label';
                    lbl.style.marginTop = '12px';
                    lbl.textContent = 'Accepted answer';
                    const ans = document.createElement('input');
                    ans.className = 'ac-input';
                    ans.placeholder = 'The answer that counts as correct';
                    ans.value = q.correct_text || '';
                    ans.addEventListener('input', e => q.correct_text = e.target.value);
                    card.appendChild(lbl);
                    card.appendChild(ans);
                } else {
                    const wrap = document.createElement('div');
                    wrap.style.marginTop = '12px';
                    q.options.forEach((opt, oi) => {
                        const row = document.createElement('div');
                        row.className = 'q-opt' + (opt.is_correct ? ' correct' : '');

                        const radio = document.createElement('input');
                        radio.type = 'radio';
                        radio.className = 'q-opt-radio';
                        radio.name = 'correct_' + q.uid;
                        radio.checked = !!opt.is_correct;
                        radio.title = 'Mark as the correct answer';
                        radio.addEventListener('change', () => setCorrect(i, oi));

                        const txt = document.createElement('input');
                        txt.type = 'text';
                        txt.value = opt.option_text;
                        txt.placeholder = 'Option ' + String.fromCharCode(65 + oi);
                        txt.disabled = q.question_type === 'true_false';
                        txt.addEventListener('input', e => opt.option_text = e.target.value);

                        row.appendChild(radio);
                        row.appendChild(txt);

                        if (q.question_type === 'multiple_choice' && q.options.length > 2) {
                            row.appendChild(toolBtn('Remove option', 'M6 18L18 6M6 6l12 12', () => removeOption(i, oi), true));
                        }
                        wrap.appendChild(row);
                    });

                    if (q.question_type === 'multiple_choice') {
                        const add = document.createElement('button');
                        add.type = 'button';
                        add.className = 'btn btn-ghost btn-sm';
                        add.textContent = '+ Add option';
                        add.addEventListener('click', () => addOption(i));
                        wrap.appendChild(add);
                    }
                    card.appendChild(wrap);
                }

                // Hint
                const hint = document.createElement('input');
                hint.className = 'ac-input';
                hint.style.marginTop = '10px';
                hint.placeholder = 'Optional hint shown to the mentee';
                hint.value = q.hint || '';
                hint.addEventListener('input', e => q.hint = e.target.value);
                card.appendChild(hint);

                // Foot: points + required
                const foot = document.createElement('div');
                foot.className = 'q-foot';

                const pts = document.createElement('label');
                pts.className = 'q-points';
                pts.textContent = 'Points';
                const ptsInput = document.createElement('input');
                ptsInput.type = 'number';
                ptsInput.min = '1';
                ptsInput.max = '100';
                ptsInput.value = q.points;
                ptsInput.addEventListener('input', e => {
                    q.points = Math.max(1, parseInt(e.target.value || '1', 10));
                    updateSummary();
                });
                pts.appendChild(ptsInput);

                const req = document.createElement('label');
                req.className = 'q-toggle';
                const reqInput = document.createElement('input');
                reqInput.type = 'checkbox';
                reqInput.checked = !!q.is_required;
                reqInput.addEventListener('change', e => q.is_required = e.target.checked ? 1 : 0);
                const track = document.createElement('span');
                track.className = 'q-toggle-track';
                req.appendChild(reqInput);
                req.appendChild(track);
                req.appendChild(document.createTextNode('Make this question required'));

                foot.appendChild(pts);
                foot.appendChild(req);
                card.appendChild(foot);

                list.appendChild(card);
            });

            // Submitted attempts freeze the question set — show it, don't let it be edited.
            if (QUESTIONS_LOCKED) {
                list.querySelectorAll('button').forEach(b => b.remove());
                list.querySelectorAll('input, select, textarea').forEach(el => el.disabled = true);
            }

            updateSummary();
        }

        function toolBtn(title, path, onClick, danger) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'q-tool' + (danger ? ' danger' : '');
            b.title = title;
            b.setAttribute('aria-label', title);
            b.innerHTML = '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="' + path + '"/></svg>';
            b.addEventListener('click', onClick);
            return b;
        }

        function updateSummary() {
            const pts = questions.reduce((s, q) => s + (parseInt(q.points, 10) || 0), 0);
            const time = document.getElementById('acTimeLimit').value;
            document.getElementById('sumQuestions').textContent = questions.length;
            document.getElementById('sumPoints').textContent = pts;
            document.getElementById('sumTime').textContent = time ? time + ' minutes' : 'None';
            const types = [...new Set(questions.map(q => TYPE_LABEL[q.question_type]))];
            document.getElementById('sumTypes').textContent = types.length ? types.join(', ') : '—';
            document.getElementById('setTime').textContent = time ? time + ' minutes' : 'Untimed';
            document.getElementById('setPoints').textContent = pts + ' pts';
        }

        document.getElementById('acTimeLimit').addEventListener('input', updateSummary);

        function renderReview() {
            document.getElementById('setAudience').textContent = 'Your mentees';
            const box = document.getElementById('reviewBody');
            const title = document.getElementById('acTitle').value.trim();
            const topic = document.getElementById('acTopic').value;
            if (!questions.length) {
                box.innerHTML = '<p style="font-size:13px;color:var(--gray-400);margin:0;">Add at least one question before publishing.</p>';
                return;
            }
            box.innerHTML = '';
            const head = document.createElement('div');
            head.style.marginBottom = '10px';
            head.innerHTML = '<div style="font-size:15px;font-weight:700;color:var(--forest);">' +
                escapeHtml(title || 'Untitled assessment') + '</div>' +
                '<div style="font-size:12.5px;color:var(--gray-500);margin-top:3px;">' +
                escapeHtml(topic || 'No topic') + ' · ' + questions.length + ' questions</div>';
            box.appendChild(head);

            questions.forEach((q, i) => {
                const d = document.createElement('div');
                d.className = 'ac-review-q';
                const correct = q.question_type === 'short_answer'
                    ? (q.correct_text || '—')
                    : (q.options.find(o => o.is_correct)?.option_text || '—');
                d.innerHTML =
                    '<div style="font-size:13px;font-weight:600;color:var(--gray-800);">' +
                    (i + 1) + '. ' + escapeHtml(q.question_text || '(no question text)') +
                    ' <span style="font-weight:500;color:var(--gray-400);">· ' + q.points + ' pts</span></div>' +
                    '<div style="font-size:12px;color:var(--gray-500);margin-top:3px;">' +
                    TYPE_LABEL[q.question_type] + ' · Correct: <b style="color:var(--success);">' + escapeHtml(correct) + '</b></div>';
                box.appendChild(d);
            });
        }

        function escapeHtml(s) {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
        }

        /* ── Submit: serialise the builder into one hidden field ─────────── */
        document.getElementById('acForm').addEventListener('submit', function(e) {
            const publishing = document.getElementById('acPublish').value === '1';
            const usable = questions.filter(q => q.question_text.trim() !== '');
            if (publishing && usable.length === 0) {
                e.preventDefault();
                alert('Add at least one question before publishing. You can still Save as Draft.');
                goStep(2);
                return;
            }
            document.getElementById('acQuestionsJson').value = JSON.stringify(questions);
        });

        /* ── Boot ────────────────────────────────────────────────────────── */
        (function() {
            if (EXISTING_QUESTIONS.length) {
                questions = EXISTING_QUESTIONS.map(q => {
                    qSeq++;
                    return {
                        uid: 'q' + qSeq,
                        question_type: q.question_type,
                        question_text: q.question_text,
                        hint: q.hint || '',
                        points: parseInt(q.points, 10) || 1,
                        is_required: parseInt(q.is_required, 10) ? 1 : 0,
                        correct_text: q.correct_text || '',
                        options: (q.options || []).map(o => ({
                            option_text: o.option_text,
                            is_correct: parseInt(o.is_correct, 10) ? 1 : 0
                        }))
                    };
                });
            } else {
                questions = [blankQuestion('multiple_choice')];
            }
            renderQuestions();
        })();
    </script>
</body>

</html>
