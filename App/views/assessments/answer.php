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

/** A posted number, or 0 when it arrived as a list or not at all. */
$posted = fn(string $key) => is_scalar($_POST[$key] ?? null) ? (int)$_POST[$key] : 0;

$mentee_id   = (int)$_SESSION['user_id'];
$attempt_id  = $posted('attempt_id');
$question_id = $posted('question_id');
$option_id   = isset($_POST['option_id']) && is_scalar($_POST['option_id']) && $_POST['option_id'] !== '' ? (int)$_POST['option_id'] : null;
$answer_text = isset($_POST['answer_text']) ? AssessmentService::text($_POST['answer_text'], AssessmentService::ANSWER_MAX) : null;
$is_flagged  = (is_scalar($_POST['is_flagged'] ?? null) ? (string)$_POST['is_flagged'] : '0') === '1' ? 1 : 0;

// The attempt must belong to this mentee and still be open. Elapsed time is
// measured by the database so a paused or tampered browser clock can't buy
// extra time on a timed assessment.
$attempt = AssessmentRepository::openAttemptWithAssessment($con, $attempt_id, $mentee_id);
if (!$attempt) {
    echo json_encode(['success' => false, 'error' => 'This attempt is closed.']);
    exit;
}

if (AssessmentService::timeIsUp($attempt['time_limit_minutes'] === null ? null : (int)$attempt['time_limit_minutes'], (int)$attempt['elapsed'])) {
    echo json_encode(['success' => false, 'error' => 'Time is up for this assessment.']);
    exit;
}

// The question must belong to the same assessment.
$question = AssessmentRepository::question($con, $question_id, (int)$attempt['assessment_id']);
if (!$question) {
    echo json_encode(['success' => false, 'error' => 'Unknown question.']);
    exit;
}

// Score this answer now so submitting is just a sum.
$option = null;
if ($question['question_type'] === 'short_answer') {
    $option_id = null;
} elseif ($option_id !== null) {
    $option = AssessmentRepository::option($con, $option_id, $question_id);
    if (!$option) {
        echo json_encode(['success' => false, 'error' => 'Unknown option.']);
        exit;
    }
}

$marked = AssessmentService::mark($question, $option, $answer_text);

AssessmentRepository::saveAnswer($con, $attempt_id, $question_id, $option_id, $answer_text,
    $marked['is_correct'], $marked['points'], $is_flagged);

echo json_encode(['success' => true]);
