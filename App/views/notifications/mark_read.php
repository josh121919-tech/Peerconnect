<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../../config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }

// CSRF check
if (!verify_csrf()) { http_response_code(403); echo json_encode(['ok'=>false]); exit; }

$raw = is_string($_POST['notification_id'] ?? null) ? $_POST['notification_id'] : '';
$nid = ctype_digit($raw) ? (int)$raw : 0;

if ($nid) {
    NotificationRepository::markRead($con, $nid, (int)$_SESSION['user_id']);
}

echo json_encode(['ok' => true]);
