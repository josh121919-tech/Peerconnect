<?php
session_start();
include __DIR__ . "/../../db.php";

header('Content-Type: application/json');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mentee') {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh and try again.']);
    exit;
}

$mentee_id = (int)$_SESSION['user_id'];
$action    = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

if ($action === 'create') {
    // A title sent as a list counts as missing.
    $title = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
    if ($title === '' || mb_strlen($title) > 150) {
        echo json_encode(['success' => false, 'message' => 'Please enter a goal title (up to 150 characters).']);
        exit;
    }
    $goal_id = GoalRepository::createForMentee($con, $mentee_id, $title);
    echo json_encode(['success' => true, 'goal_id' => $goal_id, 'title' => $title, 'status' => 'not_started']);
    exit;
}

if ($action === 'cycle_status') {
    $goal_id = is_string($_POST['goal_id'] ?? null) ? (int)$_POST['goal_id'] : 0;
    $order   = ['not_started' => 'in_progress', 'in_progress' => 'completed', 'completed' => 'not_started'];

    // Scoped to this mentee's own goal — never trust a client-supplied status.
    $status = GoalRepository::statusForMentee($con, $goal_id, $mentee_id);

    if ($status === null) {
        echo json_encode(['success' => false, 'message' => 'Goal not found.']);
        exit;
    }

    $next = $order[$status];
    $completedAt = $next === 'completed' ? date('Y-m-d H:i:s') : null;

    GoalRepository::setStatusForMentee($con, $goal_id, $mentee_id, $next, $completedAt);

    echo json_encode(['success' => true, 'status' => $next]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
