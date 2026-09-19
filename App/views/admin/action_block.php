<?php
/**
 * action_block.php — SECURITY: Admin-only, POST-only, CSRF-checked, prepared statements.
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
 * Two ways in:
 *   report_id — from the Reported queue; the report is resolved as well.
 *   user_id   — straight from a row in User Management, with no report.
 * Blocking used to require a report, so an admin who simply needed to stop
 * an account had to invent one first.
 *
 * A field sent as a list counts as missing: (int) of a list is 1, so a
 * user_id sent that way used to act on account #1.
 */
$field     = fn(string $name): string => is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
$report_id = ctype_digit($field('report_id')) ? (int)$field('report_id') : 0;
$user_id   = ctype_digit($field('user_id')) ? (int)$field('user_id') : 0;
// SECURITY: reason sanitised with htmlspecialchars on output; stored raw here
$reason    = $field('reason');
$back      = url('admin-users') . '?tab=blocked';
$from      = url('admin-users') . '?tab=' . ($report_id ? 'reported' : 'blocked');

$refuse = function (string $message, string $title = 'Not blocked') use ($from): never {
    pc_flash('error', $message, $title);
    header('Location: ' . $from);
    exit;
};

if ((!$report_id && !$user_id) || $reason === '') {
    header('Location: ' . $back . '&error=missing');
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

// An admin must not be able to block themselves out of the panel.
if ($user_id === (int)($_SESSION['user_id'] ?? 0)) {
    header('Location: ' . $back . '&error=self');
    exit;
}

// The account row stays locked until the block is saved, so two admins
// acting on one account at once take turns.
$con->begin_transaction();
$target = UserRepository::moderationTarget($con, $user_id, true);

if (!$target) {
    $con->rollback();
    $refuse('That account could not be found, so nothing changed.', 'Not found');
}
if (ModerationService::isDeleted($target)) {
    $con->rollback();
    $refuse('That account was deleted by its owner, so there is nothing to block.'
        . ($report_id ? ' Use Dismiss report to close the report.' : ''));
}

// Blocking an account that is already blocked (a second report against the
// same person, say) used to record a second block and send a second "Account
// Blocked" notice. The report is still resolved; nothing else changes.
if ($target['status'] === 'blocked') {
    $resolved = $report_id ? ModerationRepository::resolveReport($con, $report_id) : 0;
    $con->commit();
    if ($resolved) {
        pc_admin_log('resolved report #' . $report_id . ' against ' . pc_user_name($con, $user_id) . ', who was already blocked');
    }
    pc_flash('info', 'That account was already blocked, so nothing else changed.'
        . ($resolved ? ' The report is marked resolved.' : ''), 'Already blocked');
    header('Location: ' . $from);
    exit;
}

// Read before the block, so the notices below can still name the person.
$ahead = AccountClosureService::sessionsAhead($con, $user_id);

ModerationRepository::recordBlock($con, $user_id, $reason);
UserRepository::setStatus($con, $user_id, 'blocked');
if ($report_id) {
    ModerationRepository::resolveReport($con, $report_id);
}
$con->commit();

// Notify the blocked user, with the reason that was recorded.
NotificationService::accountBlocked($con, $user_id, $reason);

// Their sessions still ahead cannot happen now. Called off, and the other
// person in each told, rather than left for the missed-session job to
// record as missed by both.
$cancelled = AccountClosureService::cancelSessions($con, $user_id, $ahead);

// The reason itself is kept in `blocks`; the log says who and when.
pc_admin_log('blocked ' . pc_user_name($con, $user_id) . ($report_id ? ' from report #' . $report_id : '')
    . ($cancelled ? ' and cancelled ' . $cancelled . ' upcoming session' . ($cancelled === 1 ? '' : 's') : ''));

pc_flash('success', 'Account blocked. They are signed out on their next request.'
    . ($cancelled ? ' ' . $cancelled . ' upcoming session' . ($cancelled === 1 ? ' was' : 's were') . ' cancelled, and the other people told.' : ''),
    'Blocked');
header('Location: ' . $back);
exit;
