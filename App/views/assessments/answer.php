<?php
// Save one answer as the mentee works through an assessment.
// Correctness is decided here, not in the browser — the client never sees
// which option is right until the attempt is submitted.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";
header('Content-Type: application/json');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mentee') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security token mismatch.']);
    exit;
}

$mentee_id   = (int)$_SESSION['user_id'];
$attempt_id  = (int)($_POST['attempt_id'] ?? 0);
$question_id = (int)($_POST['question_id'] ?? 0);
$option_id   = isset($_POST['option_id']) && $_POST['option_id'] !== '' ? (int)$_POST['option_id'] : null;
$answer_text = isset($_POST['answer_text']) ? mb_substr(trim(strip_tags((string)$_POST['answer_text'])), 0, 500) : null;
$is_flagged  = ($_POST['is_flagged'] ?? '0') === '1' ? 1 : 0;

// The attempt must belong to this mentee and still be open. Elapsed time is
// measured by the database so a paused or tampered browser clock can't buy
// extra time on a timed assessment.
$stmt = $con->prepare("
    SELECT at.attempt_id, at.assessment_id, a.time_limit_minutes,
           TIMESTAMPDIFF(SECOND, at.started_at, NOW()) AS elapsed
    FROM assessment_attempts at
    JOIN assessments a ON a.assessment_id = at.assessment_id
    WHERE at.attempt_id = ? AND at.mentee_id = ? AND at.status = 'in_progress'
");
$stmt->bind_param("ii", $attempt_id, $mentee_id);
$stmt->execute();
$attempt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$attempt) {
    echo json_encode(['success' => false, 'error' => 'This attempt is closed.']);
    exit;
}

$limit = (int)$attempt['time_limit_minutes'];
if ($limit > 0 && (int)$attempt['elapsed'] > $limit * 60) {
    echo json_encode(['success' => false, 'error' => 'Time is up for this assessment.']);
    exit;
}

// The question must belong to the same assessment.
$qs = $con->prepare("SELECT question_id, question_type, points, correct_text FROM assessment_questions WHERE question_id = ? AND assessment_id = ?");
$qs->bind_param("ii", $question_id, $attempt['assessment_id']);
$qs->execute();
$question = $qs->get_result()->fetch_assoc();
$qs->close();

if (!$question) {
    echo json_encode(['success' => false, 'error' => 'Unknown question.']);
    exit;
}

// Score this answer now so submitting is just a sum.
$is_correct = 0;
if ($question['question_type'] === 'short_answer') {
    $expected = trim((string)$question['correct_text']);
    if ($expected !== '' && $answer_text !== null) {
        $is_correct = strcasecmp(trim($answer_text), $expected) === 0 ? 1 : 0;
    }
    $option_id = null;
} elseif ($option_id !== null) {
    $os = $con->prepare("SELECT is_correct FROM assessment_options WHERE option_id = ? AND question_id = ?");
    $os->bind_param("ii", $option_id, $question_id);
    $os->execute();
    $opt = $os->get_result()->fetch_assoc();
    $os->close();
    if (!$opt) {
        echo json_encode(['success' => false, 'error' => 'Unknown option.']);
        exit;
    }
    $is_correct = (int)$opt['is_correct'] === 1 ? 1 : 0;
}

$points_earned = $is_correct ? (int)$question['points'] : 0;

$up = $con->prepare("
    INSERT INTO assessment_answers
        (attempt_id, question_id, selected_option_id, answer_text, is_correct, points_earned, is_flagged)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        selected_option_id = VALUES(selected_option_id),
        answer_text        = VALUES(answer_text),
        is_correct         = VALUES(is_correct),
        points_earned      = VALUES(points_earned),
        is_flagged         = VALUES(is_flagged),
        answered_at        = NOW()
");
$up->bind_param("iiisiii", $attempt_id, $question_id, $option_id, $answer_text, $is_correct, $points_earned, $is_flagged);
$up->execute();
$up->close();

echo json_encode(['success' => true]);
