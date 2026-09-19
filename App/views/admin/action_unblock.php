<?php
/**
 * action_unblock.php
 * SECURITY: Admin-only, POST-only, CSRF-checked, prepared statements.
 *
 * The block rows are kept: they are the account's history, shown on the
 * admin's view of the account and in Activity Logs. Unblocking used to delete
 * them, so the reason for a block was gone the moment it was lifted.
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

$raw     = is_string($_POST['user_id'] ?? null) ? trim($_POST['user_id']) : '';
$user_id = ctype_digit($raw) ? (int)$raw : 0;
$back    = url('admin-users') . '?tab=blocked';
if (!$user_id) { header('Location: ' . url('admin-users')); exit; }

$refuse = function (string $message, string $title) use ($back): never {
    pc_flash('error', $message, $title);
    header('Location: ' . $back);
    exit;
};

$con->begin_transaction();
$target = UserRepository::moderationTarget($con, $user_id, true);

if (!$target) {
    $con->rollback();
    $refuse('That account could not be found, so nothing changed.', 'Not found');
}
if (ModerationService::isDeleted($target)) {
    $con->rollback();
    $refuse('That account was deleted by its owner, so it cannot be unblocked.', 'Not unblocked');
}
// Only a blocked account is unblocked. From a page left open, this used to
// set any account active — ending a running restriction — and send another
// "Account Restored" notice on every repeat.
if ($target['status'] !== 'blocked') {
    $con->rollback();
    pc_flash('info', 'That account is not blocked, so nothing changed.', 'Not blocked');
    header('Location: ' . $back);
    exit;
}

// Someone restricted before they were blocked goes back to serving the rest
// of that restriction, not straight to a clean slate.
$lifts_on = ModerationService::liftsOn($con, $user_id);
UserRepository::setStatus($con, $user_id, $lifts_on !== null ? 'restricted' : 'active');
$con->commit();

$still = $lifts_on !== null ? ' Their restriction still runs until ' . date('F j, Y', strtotime($lifts_on)) . '.' : '';
pc_admin_log('unblocked ' . pc_user_name($con, $user_id) . ($lifts_on !== null ? ', still restricted until ' . $lifts_on : ''));

// Tell them, so they are not left guessing whether they can sign in.
NotificationService::accountUnblocked($con, $user_id, url('login'));

pc_flash('success', 'Account unblocked — they can sign in again.' . $still, 'Unblocked');
header('Location: ' . $back);
exit;
