<?php
// get_messages.php
// Called by JS every 3 seconds via AJAX to fetch new messages.

session_start();
include __DIR__ . "/../db.php";
header('Content-Type: application/json');
// PDO is opened on demand now rather than on every request in db.php — these
// two files are the only ones in the app that use it.
$pdo = pc_pdo();


// ── Auth check ────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$myId    = (int) $_SESSION['user_id'];
$chatId  = isset($_GET['chat_id'])  ? (int) $_GET['chat_id']  : 0;
$lastId  = isset($_GET['last_id'])  ? (int) $_GET['last_id']  : 0;

if (!$chatId) {
    echo json_encode(['messages' => []]);
    exit;
}

// ── Fetch only messages newer than last_id ────────────────
$stmt = $pdo->prepare("
    SELECT id, sender_id, content, created_at
    FROM messages
    WHERE id > ?
      AND (
        (sender_id = ? AND receiver_id = ?)
        OR
        (sender_id = ? AND receiver_id = ?)
      )
    ORDER BY id ASC
    LIMIT 50
");
$stmt->execute([$lastId, $myId, $chatId, $chatId, $myId]);
$messages = $stmt->fetchAll();

// ── Mark incoming messages as read ───────────────────────
if (!empty($messages)) {
    $pdo->prepare("
        UPDATE messages SET is_read = 1
        WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
    ")->execute([$chatId, $myId]);
}

echo json_encode(['messages' => $messages]);
