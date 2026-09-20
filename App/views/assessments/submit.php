<?php
// Finalise an attempt: total the per-answer points and lock it. The attempt
// stays on the record afterwards, so the mentee can take the assessment again
// and both results are there to compare.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";
require_once __DIR__ . '/../../services/NotificationService.php';
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

$mentee_id  = (int)$_SESSION['user_id'];
$attempt_id = is_scalar($_POST['attempt_id'] ?? null) ? (int)$_POST['attempt_id'] : 0;

$attempt = AssessmentRepository::openAttemptWithAssessment($con, $attempt_id, $mentee_id);
if (!$attempt) {
    echo json_encode(['success' => false, 'error' => 'This attempt has already been submitted.']);
    exit;
}

$assessment_id = (int)$attempt['assessment_id'];

// Totals come from the stored per-answer scores and the question set.
$totals = AssessmentRepository::attemptTotals($con, $attempt_id, $assessment_id);
$score  = $totals['score'];
$total  = $totals['total'];

if (AssessmentRepository::submitAttempt($con, $attempt_id, $mentee_id, $score, $total) < 1) {
    echo json_encode(['success' => false, 'error' => 'This attempt has already been submitted.']);
    exit;
}

// Let the mentor know, reusing the existing notification service.
try {
    $names = UserRepository::names($con, $mentee_id);
    $who   = $names ? trim($names['firstname'] . ' ' . $names['lastname']) : 'A mentee';
    $pct   = $total > 0 ? round($score / $total * 100) : 0;
    $tries = AssessmentRepository::countSubmittedBy($con, $assessment_id, $mentee_id);
    NotificationService::send(
        $con,
        (int)$attempt['mentor_id'],
        'assessment_submitted',
        'Assessment submitted',
        $who . ' scored ' . $pct . '% on "' . $attempt['title'] . '"'
            . ($tries > 1 ? ' (' . AssessmentService::attemptLabel($tries) . ').' : '.'),
        url('assessment-results') . '?id=' . $assessment_id
    );
} catch (Throwable $e) {
    // A failed notification must never fail the submission itself.
}

echo json_encode(['success' => true, 'score' => $score, 'total' => $total]);
