<?php
session_start();
include __DIR__ . "/../../db.php";

header('Content-Type: application/json');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mentee') {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh and try again.']);
    exit;
}

$mentee_id = (int)$_SESSION['user_id'];
$action    = $_POST['action'] ?? '';

if ($action === 'create') {
    $title = trim($_POST['title'] ?? '');
    if ($title === '' || mb_strlen($title) > 150) {
        echo json_encode(['success' => false, 'message' => 'Please enter a goal title (up to 150 characters).']);
        exit;
    }
    $stmt = $con->prepare("INSERT INTO goals (mentee_id, title, created_by) VALUES (?, ?, ?)");
    $stmt->bind_param("isi", $mentee_id, $title, $mentee_id);
    $ok = $stmt->execute();
    $goal_id = $stmt->insert_id;
    $stmt->close();
    echo json_encode(['success' => $ok, 'goal_id' => $goal_id, 'title' => $title, 'status' => 'not_started']);
    exit;
}

if ($action === 'cycle_status') {
    $goal_id = (int)($_POST['goal_id'] ?? 0);
    $order   = ['not_started' => 'in_progress', 'in_progress' => 'completed', 'completed' => 'not_started'];

    // Scoped to this mentee's own goal — never trust a client-supplied status.
    $stmt = $con->prepare("SELECT status FROM goals WHERE goal_id = ? AND mentee_id = ? LIMIT 1");
    $stmt->bind_param("ii", $goal_id, $mentee_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Goal not found.']);
        exit;
    }

    $next = $order[$row['status']];
    $completedAt = $next === 'completed' ? date('Y-m-d H:i:s') : null;

    $stmt = $con->prepare("UPDATE goals SET status = ?, completed_at = ? WHERE goal_id = ? AND mentee_id = ?");
    $stmt->bind_param("ssii", $next, $completedAt, $goal_id, $mentee_id);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => $ok, 'status' => $next]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
