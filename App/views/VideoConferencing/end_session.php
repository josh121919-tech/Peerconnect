<?php
/**
 * end_session.php  –  Called via fetch/sendBeacon when a session ends
 * Place at: /case/case/videoconferencing/end_session.php
 *
 * Does NOT mark status='completed' here — that happens in feedback.php
 * after the mentee submits feedback (so the session counts only once).
 * For mentors, it marks the session completed immediately since they skip feedback.
 */
date_default_timezone_set('Asia/Manila');
session_start();
include __DIR__ . "/../db.php";
require_once __DIR__ . "/../../services/NotificationService.php";
require_once __DIR__ . "/../../services/AvailabilityService.php";

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'not logged in']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'mentee';

$body       = json_decode(file_get_contents('php://input'), true);
$session_id = (int)($body['session_id'] ?? 0);

if (!verify_csrf_token($body['csrf_token'] ?? null)) {
    echo json_encode(['ok' => false, 'error' => 'invalid csrf token']);
    exit;
}

if (!rate_limit('video_end_' . $user_id, 10, 60)) {
    echo json_encode(['ok' => false, 'error' => 'too many requests']);
    exit;
}

if (!$session_id) {
    echo json_encode(['ok' => false, 'error' => 'missing session_id']);
    exit;
}

// Verify the user belongs to this session
$stmt = $con->prepare("
    SELECT sr.request_id, sr.mentor_id, sr.mentee_id,
           mentor.firstname AS mentor_fname, mentor.lastname AS mentor_lname,
           mentee.firstname AS mentee_fname, mentee.lastname AS mentee_lname
    FROM session_requests sr
    JOIN users mentor ON sr.mentor_id = mentor.user_id
    JOIN users mentee ON sr.mentee_id = mentee.user_id
    WHERE sr.request_id = ?
      AND (sr.mentor_id = ? OR sr.mentee_id = ?)
      AND sr.status = 'approved'
");
$stmt->bind_param("iii", $session_id, $user_id, $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'session not found or already ended']);
    exit;
}

if ($role === 'mentor') {
    // Mentor ends → mark completed immediately (counts as 1 session for the mentor)
    // completed_at matters for exports and for any month-over-month figure;
    // it was never being set here. NOW() keeps it on the database's clock.
    $upd = $con->prepare("
        UPDATE session_requests SET status = 'completed', completed_at = NOW()
        WHERE request_id = ? AND mentor_id = ?
    ");
    $upd->bind_param("ii", $session_id, $user_id);
    $upd->execute();

    AvailabilityService::removeIfFullyCompleted($con, $session_id);

    NotificationService::send(
        $con,
        (int)$row['mentee_id'],
        'session_ended',
        'Session Ended',
        "Your session with {$row['mentor_fname']} {$row['mentor_lname']} has ended.",
        url('mentee-submit-feedback') . '?session_id=' . $session_id . '&mentor_id=' . $row['mentor_id']
    );
} else {
    // Mentee's side: leave status as 'approved' — feedback.php will set it to 'completed'
    // after the mentee submits their rating.
    NotificationService::send(
        $con,
        (int)$row['mentor_id'],
        'session_ended',
        'Session Ended',
        "{$row['mentee_fname']} {$row['mentee_lname']} left the session.",
        url('mentor-completed')
    );
}

echo json_encode(['ok' => true]);
