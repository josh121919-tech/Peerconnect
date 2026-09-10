<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../../config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }

// CSRF check
$csrf = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) { http_response_code(403); echo json_encode(['ok'=>false]); exit; }

$uid  = (int)$_SESSION['user_id'];
$nid  = (int)($_POST['notification_id'] ?? 0);

if ($nid) {
    $stmt = $con->prepare("UPDATE notifications SET is_read=1 WHERE notification_id=? AND user_id=?");
    $stmt->bind_param("ii", $nid, $uid);
    $stmt->execute();
    $stmt->close();
}

echo json_encode(['ok' => true]);
