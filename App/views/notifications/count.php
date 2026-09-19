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

echo json_encode(['count' => NotificationRepository::countUnread($con, (int)$_SESSION['user_id'])]);
