<?php
/**
 * action_resolve.php
 * SECURITY: Admin-only, POST-only, prepared statement.
 */
session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';

require_admin();
require_post();

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    exit('CSRF token mismatch.');
}

$report_id = (int)($_POST['report_id'] ?? 0);
if (!$report_id) {
    header('Location: ' . url('admin-users') . '?tab=resolved');
    exit;
}

$stmt = $con->prepare("UPDATE reports SET status='resolved', updated_at=NOW() WHERE report_id=?");
$stmt->bind_param("i", $report_id);
$stmt->execute();
$stmt->close();

$who = $con->prepare("SELECT reported_user_id FROM reports WHERE report_id = ?");
$who->bind_param("i", $report_id);
$who->execute();
$reported = $who->get_result()->fetch_row();
$who->close();
if ($reported) {
    pc_admin_log('dismissed report #' . $report_id . ' against ' . pc_user_name($con, (int)$reported[0]));
}

pc_flash('success', 'Report dismissed.', 'Resolved');
header('Location: ' . url('admin-users') . '?tab=resolved');
exit;
