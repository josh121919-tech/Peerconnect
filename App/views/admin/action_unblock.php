<?php
/**
 * action_unblock.php
 * SECURITY: Admin-only, POST-only, CSRF-checked, prepared statement used.
 */
session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../../services/NotificationService.php';

require_admin();
require_post();

if (!verify_csrf()) {
    http_response_code(403);
    exit('CSRF token mismatch.');
}

$user_id = (int)($_POST['user_id'] ?? 0);
if (!$user_id) { header('Location: ' . url('admin-users')); exit; }

$stmt = $con->prepare("UPDATE users SET status = 'active' WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$changed = $stmt->affected_rows;
$stmt->close();

$stmt2 = $con->prepare("DELETE FROM blocks WHERE user_id = ?");
$stmt2->bind_param("i", $user_id);
$stmt2->execute();
$changed += $stmt2->affected_rows;
$stmt2->close();

if ($changed > 0) {
    pc_admin_log('unblocked ' . pc_user_name($con, $user_id));
}

// Tell them, so they are not left guessing whether they can sign in.
NotificationService::accountUnblocked($con, $user_id, url('login'));

pc_flash('success', 'Account unblocked — they can sign in again.', 'Unblocked');
header('Location: ' . url('admin-users') . '?tab=blocked');
exit;
