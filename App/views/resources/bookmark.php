<?php
// Resources — save/unsave a resource (powers the "Saved Resources" panel).
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security token mismatch.']);
    exit;
}

$user_id     = (int)$_SESSION['user_id'];
$resource_id = (int)($_POST['resource_id'] ?? 0);
if (!$resource_id) {
    echo json_encode(['success' => false, 'error' => 'Missing resource']);
    exit;
}

// Only bookmark something that actually exists and is still shared.
$chk = $con->prepare("SELECT 1 FROM resources WHERE resource_id = ? AND is_active = 1");
$chk->bind_param("i", $resource_id);
$chk->execute();
$exists = $chk->get_result()->num_rows > 0;
$chk->close();
if (!$exists) {
    echo json_encode(['success' => false, 'error' => 'Resource not found']);
    exit;
}

$has = $con->prepare("SELECT 1 FROM resource_bookmarks WHERE user_id = ? AND resource_id = ?");
$has->bind_param("ii", $user_id, $resource_id);
$has->execute();
$saved = $has->get_result()->num_rows > 0;
$has->close();

if ($saved) {
    $del = $con->prepare("DELETE FROM resource_bookmarks WHERE user_id = ? AND resource_id = ?");
    $del->bind_param("ii", $user_id, $resource_id);
    $del->execute();
    $del->close();
    echo json_encode(['success' => true, 'saved' => false]);
} else {
    $ins = $con->prepare("INSERT INTO resource_bookmarks (user_id, resource_id) VALUES (?, ?)");
    $ins->bind_param("ii", $user_id, $resource_id);
    $ins->execute();
    $ins->close();
    echo json_encode(['success' => true, 'saved' => true]);
}
