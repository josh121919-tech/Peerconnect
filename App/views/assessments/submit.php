<?php
// Finalise an attempt: total the per-answer points and lock it.
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
$attempt_id = (int)($_POST['attempt_id'] ?? 0);

$stmt = $con->prepare("
    SELECT at.attempt_id, at.assessment_id, a.title, a.mentor_id
    FROM assessment_attempts at
    JOIN assessments a ON a.assessment_id = at.assessment_id
    WHERE at.attempt_id = ? AND at.mentee_id = ? AND at.status = 'in_progress'
");
$stmt->bind_param("ii", $attempt_id, $mentee_id);
$stmt->execute();
$attempt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$attempt) {
    echo json_encode(['success' => false, 'error' => 'This attempt has already been submitted.']);
    exit;
}

$assessment_id = (int)$attempt['assessment_id'];

// Totals come from the stored per-answer scores and the question set.
$score = (int)($con->query("
    SELECT COALESCE(SUM(points_earned), 0) c FROM assessment_answers WHERE attempt_id = $attempt_id
")->fetch_assoc()['c'] ?? 0);

$total = (int)($con->query("
    SELECT COALESCE(SUM(points), 0) c FROM assessment_questions WHERE assessment_id = $assessment_id
")->fetch_assoc()['c'] ?? 0);

$fin = $con->prepare("
    UPDATE assessment_attempts
       SET status = 'submitted', submitted_at = NOW(), score = ?, total_points = ?
     WHERE attempt_id = ? AND mentee_id = ? AND status = 'in_progress'
");
$fin->bind_param("iiii", $score, $total, $attempt_id, $mentee_id);
$fin->execute();
$changed = $fin->affected_rows;
$fin->close();

if ($changed < 1) {
    echo json_encode(['success' => false, 'error' => 'This attempt has already been submitted.']);
    exit;
}

// Let the mentor know, reusing the existing notification service.
try {
    $nameRow = $con->query("SELECT firstname, lastname FROM users WHERE user_id = $mentee_id")->fetch_assoc();
    $who = $nameRow ? trim($nameRow['firstname'] . ' ' . $nameRow['lastname']) : 'A mentee';
    $pct = $total > 0 ? round($score / $total * 100) : 0;
    NotificationService::send(
        $con,
        (int)$attempt['mentor_id'],
        'assessment_submitted',
        'Assessment submitted',
        $who . ' scored ' . $pct . '% on "' . $attempt['title'] . '".',
        url('assessment-results') . '?id=' . $assessment_id
    );
} catch (Throwable $e) {
    // A failed notification must never fail the submission itself.
}

echo json_encode(['success' => true, 'score' => $score, 'total' => $total]);
