<?php
/**
 * action_resolve.php — "Dismiss report" in the Reported queue.
 * SECURITY: Admin-only, POST-only, CSRF-checked, prepared statements.
 */
session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';

require_admin();
require_post();

if (!verify_csrf()) {
    http_response_code(403);
    exit('CSRF token mismatch.');
}

// Back to the Reported queue. This used to send the admin to a "resolved"
// tab that does not exist, which showed All Users instead.
$back = url('admin-users') . '?tab=reported';

$raw       = is_string($_POST['report_id'] ?? null) ? trim($_POST['report_id']) : '';
$report_id = ctype_digit($raw) ? (int)$raw : 0;

// Sent from the Notifications page: back there, to the same report.
if (($_POST['return_to'] ?? '') === 'notifications') {
    $back = url('admin-notifications') . '?report=' . $report_id;
}
if (!$report_id) {
    header('Location: ' . $back);
    exit;
}

$report = ModerationRepository::report($con, $report_id);
if (!$report) {
    pc_flash('error', 'That report could not be found, so nothing changed.', 'Not found');
    header('Location: ' . $back);
    exit;
}

// Already resolved (by another admin, or a second click): say so, and do not
// log it a second time.
if (ModerationRepository::resolveReport($con, $report_id) < 1) {
    pc_flash('info', 'That report was already resolved, so nothing changed.', 'Already resolved');
    header('Location: ' . $back);
    exit;
}

pc_admin_log('dismissed report #' . $report_id . ' against ' . pc_user_name($con, (int)$report['reported_user_id']));

pc_flash('success', 'Report dismissed.', 'Resolved');
header('Location: ' . $back);
exit;
