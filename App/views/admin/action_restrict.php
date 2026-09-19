<?php
/**
 * action_restrict.php — SECURITY: Admin-only, POST-only, CSRF-checked, prepared statements.
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

/*
 * Like blocking, this takes either a report_id (from the Reported queue,
 * which also resolves the report) or a user_id straight from a row in User
 * Management. A field sent as a list counts as missing.
 *
 * "Change restriction" posts here too. It adds a new restriction rather than
 * editing the old one, and the newest restriction is the one in force (see
 * ModerationService), so a restriction can be shortened as well as extended.
 */
$field     = fn(string $name): string => is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
$report_id = ctype_digit($field('report_id')) ? (int)$field('report_id') : 0;
$user_id   = ctype_digit($field('user_id')) ? (int)$field('user_id') : 0;
$days      = ctype_digit($field('days')) ? (int)$field('days') : 0;
$reason    = $field('reason');
$back      = url('admin-users') . '?tab=restricted';
$from      = url('admin-users') . '?tab=' . ($report_id ? 'reported' : 'restricted');

$refuse = function (string $message, string $title = 'Not restricted') use ($from): never {
    pc_flash('error', $message, $title);
    header('Location: ' . $from);
    exit;
};

if ((!$report_id && !$user_id) || $days <= 0 || $days > 365 || $reason === '') {
    header('Location: ' . url('admin-users') . '?tab=reported&error=missing');
    exit;
}
if (mb_strlen($reason) > ModerationService::REASON_MAX) {
    $refuse('Keep the reason to ' . number_format(ModerationService::REASON_MAX) . ' characters or fewer.');
}

if ($report_id) {
    $report = ModerationRepository::report($con, $report_id);
    if (!$report) {
        $refuse('That report could not be found, so nothing changed.', 'Not found');
    }
    $user_id = (int)$report['reported_user_id'];
}

if ($user_id === (int)($_SESSION['user_id'] ?? 0)) {
    header('Location: ' . $back . '&error=self');
    exit;
}

// The account row stays locked until the restriction is saved, so two admins
// acting on one account at once take turns.
$con->begin_transaction();
$target = UserRepository::moderationTarget($con, $user_id, true);

if (!$target) {
    $con->rollback();
    $refuse('That account could not be found, so nothing changed.', 'Not found');
}
if (ModerationService::isDeleted($target)) {
    $con->rollback();
    $refuse('That account was deleted by its owner, so there is nothing to restrict.'
        . ($report_id ? ' Use Dismiss report to close the report.' : ''));
}
// Restricting a blocked account set it to "restricted", which let it sign in
// again: the block was quietly undone.
if ($target['status'] === 'blocked') {
    $con->rollback();
    $refuse('That account is blocked. Unblock it first if you want to restrict it instead.');
}
// The restriction check leaves admins alone (so an admin can never be locked
// out of the panel), so restricting one changed nothing but a label.
if ($target['role'] === 'admin') {
    $con->rollback();
    $refuse('Admin accounts cannot be restricted, because a restriction does not apply to the admin panel. Block the account if it must be stopped.');
}

$admin_id = (int) ($_SESSION['user_id'] ?? 0);
$changing = $target['status'] === 'restricted';
$last_day = ModerationService::lastDayFor($days);
$lifts_on = ModerationService::liftDay($last_day);

ModerationRepository::recordRestriction($con, $user_id, $reason, $admin_id, $last_day);
UserRepository::setStatus($con, $user_id, 'restricted');
if ($report_id) {
    ModerationRepository::resolveReport($con, $report_id);
}
$con->commit();

// The person serving the restriction is told how long it lasts and why.
NotificationService::accountRestricted($con, $user_id, $lifts_on, $days, $reason);

// The reason itself is kept in `restrictions`; the log says who and when.
pc_admin_log('restricted ' . pc_user_name($con, $user_id) . ' for ' . $days . ' day' . ($days === 1 ? '' : 's')
    . ($report_id ? ' from report #' . $report_id : ''));

pc_flash('success', ($changing ? 'Restriction changed — it now lifts itself on ' : 'Restriction applied — it lifts itself on ')
    . date('F j, Y', strtotime($lifts_on)) . '.', 'Restricted');
header('Location: ' . $back);
exit;
