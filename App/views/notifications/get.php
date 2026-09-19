<?php
/**
 * notifications/get.php
 * Returns the 15 most recent notifications for the current user.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../../config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['notifications' => []]);
    exit;
}

$rows = NotificationRepository::recentForBell($con, (int)$_SESSION['user_id'], 15);

// Add human-readable "time ago"
foreach ($rows as &$r) {
    $diff = time() - strtotime($r['created_at']);
    if ($diff < 60)         $r['time_ago'] = 'Just now';
    elseif ($diff < 3600)   $r['time_ago'] = (int)($diff/60) . 'm ago';
    elseif ($diff < 86400)  $r['time_ago'] = (int)($diff/3600) . 'h ago';
    else                    $r['time_ago'] = date('M j', strtotime($r['created_at']));
}
unset($r);

echo json_encode(['notifications' => $rows]);
