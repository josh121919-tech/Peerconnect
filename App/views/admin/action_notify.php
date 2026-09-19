<?php
/**
 * action_notify.php — an admin sends one mentor or mentee a notice from the
 * Notifications page: a ready-made notice about a report (see
 * ReportService::NOTICES), or a title and message of their own.
 *
 * SECURITY: Admin-only, POST-only, CSRF-checked, prepared statements.
 *
 * It arrives the way every other notification does — in their bell, and by
 * email when email sending is switched on. Who sent it, and about which
 * report, is kept in the activity log.
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

// A field sent as a list counts as missing.
$field = fn(string $name): string => is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
$id    = fn(string $name): int => ctype_digit($field($name)) ? (int)$field($name) : 0;

$recipient_id = $id('recipient_id');
$report_id    = $id('report_id');
$notice       = $field('notice') ?: 'custom';
$back         = url('admin-notifications') . ($report_id ? '?report=' . $report_id : '?tab=sent');

$refuse = function (string $message) use ($back): never {
    pc_flash('error', $message, 'Not sent');
    header('Location: ' . $back);
    exit;
};

$recipient = UserRepository::moderationTarget($con, $recipient_id);
if (!$recipient || ModerationService::isDeleted($recipient) || !in_array($recipient['role'], ['mentee', 'mentor'], true)) {
    $refuse('Choose a mentor or mentee to send the notice to.');
}

if ($notice === 'custom') {
    $title   = $field('title');
    $message = $field('message');
    if ($title === '' || $message === '') {
        $refuse('Give the notice a title and a message.');
    }
    if (mb_strlen($title) > NotificationService::ADMIN_TITLE_MAX || mb_strlen($message) > NotificationService::ADMIN_MESSAGE_MAX) {
        $refuse('Keep the title to ' . NotificationService::ADMIN_TITLE_MAX . ' characters and the message to '
            . number_format(NotificationService::ADMIN_MESSAGE_MAX) . '.');
    }
    $type = 'admin_notice';
    $what = 'a notice ("' . $title . '")';
} elseif (isset(ReportService::NOTICES[$notice])) {
    $report = $report_id ? ModerationRepository::reportDetail($con, $report_id) : null;
    if (!$report) {
        $refuse('That report could not be found, so nothing was sent.');
    }
    // A report notice goes only to the person it is written for.
    $toReported = ReportService::NOTICES[$notice]['to'] === 'reported';
    $expected   = $toReported ? (int)$report['reported_user_id'] : (int)$report['reported_by'];
    if ($recipient_id !== $expected) {
        $refuse('That notice can only go to ' . ($toReported ? 'the reported member.' : 'the person who filed the report.'));
    }
    [$title, $message] = ReportService::noticeText($notice, $report);
    $type = ReportService::NOTICES[$notice]['type'];
    $what = 'the "' . $title . '" notice about report #' . $report_id;
} else {
    $refuse('Choose which notice to send.');
}

NotificationService::send($con, $recipient_id, $type, $title, $message);

$who = pc_user_name($con, $recipient_id);
pc_admin_log('sent ' . $what . ' to ' . $who);

$name = UserRepository::names($con, $recipient_id);
pc_flash('success', 'Notice sent to ' . trim(($name['firstname'] ?? '') . ' ' . ($name['lastname'] ?? '')) . '.', 'Sent');
header('Location: ' . $back);
exit;
