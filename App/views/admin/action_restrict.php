<?php
/**
 * action_restrict.php — SECURITY: Admin-only, POST-only, prepared statements.
 */
session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../../services/NotificationService.php';

require_admin();
require_post();

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    exit('CSRF token mismatch.');
}

/*
 * Like blocking, this takes either a report_id (from the Reported queue,
 * which also resolves the report) or a user_id straight from a row in User
 * Management.
 */
$report_id = (int) ($_POST['report_id'] ?? 0);
$user_id   = (int) ($_POST['user_id'] ?? 0);
$days      = (int) ($_POST['days'] ?? 0);
$reason    = trim($_POST['reason'] ?? '');
$back      = url('admin-users') . '?tab=restricted';

if ((!$report_id && !$user_id) || $days <= 0 || $days > 365 || $reason === '') {
    header('Location: ' . url('admin-users') . '?tab=reported&error=missing');
    exit;
}

if ($report_id) {
    $stmt = $con->prepare("SELECT reported_user_id FROM reports WHERE report_id = ?");
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $user_id = $r ? (int) $r['reported_user_id'] : 0;
}

if ($user_id === (int)($_SESSION['user_id'] ?? 0)) {
    header('Location: ' . $back . '&error=self');
    exit;
}

if ($user_id) {
    $admin_id = (int) ($_SESSION['user_id'] ?? 0);
    $end_date = date('Y-m-d', strtotime("+{$days} days"));

    $s1 = $con->prepare("INSERT INTO restrictions (user_id, reason, restricted_by, restricted_at, start_date, end_date) VALUES (?, ?, ?, NOW(), CURDATE(), ?)");
    $s1->bind_param("isis", $user_id, $reason, $admin_id, $end_date);
    $s1->execute();
    $s1->close();

    $s2 = $con->prepare("UPDATE users SET status = 'restricted' WHERE user_id = ?");
    $s2->bind_param("i", $user_id);
    $s2->execute();
    $s2->close();

    if ($report_id) {
        $s3 = $con->prepare("UPDATE reports SET status = 'resolved', updated_at = NOW() WHERE report_id = ?");
        $s3->bind_param("i", $report_id);
        $s3->execute();
        $s3->close();
    }

    // The person serving the restriction is told how long it lasts and why.
    NotificationService::accountRestricted($con, $user_id, $end_date, $days, $reason);

    // The reason itself is kept in `restrictions`; the log says who and when.
    pc_admin_log('restricted ' . pc_user_name($con, $user_id) . ' for ' . $days . ' day' . ($days === 1 ? '' : 's')
        . ($report_id ? ' from report #' . $report_id : ''));
}

pc_flash('success', 'Restriction applied — it lifts itself on ' . date('F j, Y', strtotime($end_date)) . '.', 'Restricted');
header('Location: ' . $back);
exit;
