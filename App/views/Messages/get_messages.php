<?php
// get_messages.php
// Called by JS every 3 seconds via AJAX to fetch new messages.

session_start();
include __DIR__ . "/../db.php";
header('Content-Type: application/json');


// ── Auth check ────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$myId    = (int) $_SESSION['user_id'];
$chatId  = is_string($_GET['chat_id'] ?? null) ? (int) $_GET['chat_id'] : 0;
$lastId  = is_string($_GET['last_id'] ?? null) ? (int) $_GET['last_id'] : 0;

if (!$chatId) {
    echo json_encode(['messages' => []]);
    exit;
}

// ── Fetch only messages newer than last_id ────────────────
$messages = MessageRepository::newerThan($con, $myId, $chatId, $lastId, 50);

// ── Mark incoming messages as read ───────────────────────
if (!empty($messages)) {
    MessageRepository::markReadFrom($con, $chatId, $myId);
}

echo json_encode(['messages' => $messages]);
