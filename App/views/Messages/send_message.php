<?php
// send_message.php
// Called by JS when user clicks Send or presses Enter.

session_start();
include __DIR__ . "/../db.php";
require_once __DIR__ . '/../../services/NotificationService.php';
header('Content-Type: application/json');


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
if (!verify_csrf()) {
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

// A field sent as a list counts as missing; trim() of a list used to end the
// request with a server error.
$rawId      = is_string($_POST['receiver_id'] ?? null) ? trim($_POST['receiver_id']) : '';
$receiverId = ctype_digit($rawId) ? (int)$rawId : 0;
$content    = is_string($_POST['message'] ?? null) ? trim($_POST['message']) : '';

if (!$receiverId || $content === '') {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit;
}

// ── Who is allowed to talk to whom ────────────────────────────────────────
// One rule, shared with the Messages page (see MessageService): mentors and
// mentees message each other, admins message members, and members reply to
// an admin who wrote first.
$refusal = MessageService::refusal($con, $myId, $receiverId);
if ($refusal !== null) {
    echo json_encode(['success' => false, 'error' => $refusal]);
    exit;
}

// Limit message length
if (mb_strlen($content) > MessageService::MAX_LENGTH) {
    echo json_encode(['success' => false, 'error' => 'Message too long']);
    exit;
}

// ── Insert message ────────────────────────────────────────
try {
    $newId     = MessageRepository::send($con, $myId, $receiverId, $content);
    $createdAt = MessageRepository::createdAt($con, $newId);

    $senderRow = UserRepository::names($con, $myId);
    if ($senderRow) {
        NotificationService::newMessage($con, $receiverId, trim($senderRow['firstname'] . ' ' . $senderRow['lastname']), url('messages') . '?chat=' . $myId);
    }

    echo json_encode([
        'success'    => true,
        'message_id' => $newId,
        'created_at' => $createdAt ?: date('Y-m-d H:i:s'),
    ]);
} catch (mysqli_sql_exception $e) {
    echo json_encode(['success' => false, 'error' => 'Insert failed']);
}
