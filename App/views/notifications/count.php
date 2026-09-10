<?php
/**
 * notifications/count.php
 * Returns unread notification count for the current user.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../../config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['count' => 0]);
    exit;
}

$uid  = (int)$_SESSION['user_id'];
$stmt = $con->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$stmt->bind_param("i", $uid);
$stmt->execute();
$count = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

echo json_encode(['count' => $count]);
