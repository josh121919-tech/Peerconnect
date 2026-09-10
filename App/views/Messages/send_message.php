<?php
// send_message.php
// Called by JS when user clicks Send or presses Enter.

session_start();
include __DIR__ . "/../db.php";
require_once __DIR__ . '/../../services/NotificationService.php';
header('Content-Type: application/json');
// PDO is opened on demand now rather than on every request in db.php — these
// two files are the only ones in the app that use it.
$pdo = pc_pdo();


// ── Auth check ────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// SECURITY: CSRF check — this endpoint mutates data (inserts a message)
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security token mismatch. Please refresh and try again.']);
    exit;
}

$myId = (int) $_SESSION['user_id'];

// SECURITY: rate-limit sending to prevent spam/flood
if (!rate_limit('send_message_' . $myId, 20, 60)) {
    echo json_encode(['success' => false, 'error' => 'Too many messages. Please slow down.']);
    exit;
}

$receiverId = isset($_POST['receiver_id']) ? (int) $_POST['receiver_id'] : 0;
$content    = trim($_POST['message'] ?? '');

if (!$receiverId || $content === '') {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit;
}

// ── Who is allowed to talk to whom ────────────────────────────────────────
// Every conversation in this app is mentee <-> mentor: the only entry points
// are the Message buttons on a mentor's profile and on a session card. The
// endpoint itself accepted any user_id, so a mentee could message an admin,
// another mentee, or themselves — threads the Messages page cannot render and
// that neither role ever asked for.
$pair = $con->prepare("
    SELECT
        (SELECT role   FROM users WHERE user_id = ?) AS my_role,
        (SELECT role   FROM users WHERE user_id = ?) AS their_role,
        (SELECT status FROM users WHERE user_id = ?) AS their_status
");
$pair->bind_param("iii", $myId, $receiverId, $receiverId);
$pair->execute();
$roles = $pair->get_result()->fetch_assoc() ?: [];
$pair->close();

$myRole    = $roles['my_role']      ?? '';
$theirRole = $roles['their_role']   ?? '';
$theirStat = $roles['their_status'] ?? '';

if ($receiverId === $myId) {
    echo json_encode(['success' => false, 'error' => "You can't message yourself."]);
    exit;
}
if ($theirRole === null || $theirRole === '') {
    echo json_encode(['success' => false, 'error' => 'That person could not be found.']);
    exit;
}
if (!in_array($myRole, ['mentee', 'mentor'], true) || !in_array($theirRole, ['mentee', 'mentor'], true) || $myRole === $theirRole) {
    echo json_encode(['success' => false, 'error' => 'You can only message your mentor or mentee.']);
    exit;
}
if ($theirStat !== 'active') {
    echo json_encode(['success' => false, 'error' => 'That account is not available for messages.']);
    exit;
}

// Limit message length
if (mb_strlen($content) > 2000) {
    echo json_encode(['success' => false, 'error' => 'Message too long']);
    exit;
}

// ── Insert message ────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        INSERT INTO messages (sender_id, receiver_id, content, is_read, created_at)
        VALUES (?, ?, ?, 0, NOW())
    ");
    $stmt->execute([$myId, $receiverId, $content]);

    $newId = $pdo->lastInsertId();

    $createdAt = $pdo->query("SELECT created_at FROM messages WHERE id = " . (int)$newId)->fetchColumn();

    $nameQ = $con->prepare("SELECT firstname, lastname FROM users WHERE user_id = ?");
    $nameQ->bind_param("i", $myId);
    $nameQ->execute();
    $senderRow = $nameQ->get_result()->fetch_assoc();
    $nameQ->close();
    if ($senderRow) {
        NotificationService::newMessage($con, $receiverId, trim($senderRow['firstname'] . ' ' . $senderRow['lastname']), url('messages') . '?chat=' . $myId);
    }

    echo json_encode([
        'success'    => true,
        'message_id' => (int)$newId,
        'created_at' => $createdAt ?: date('Y-m-d H:i:s'),
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Insert failed']);
}
