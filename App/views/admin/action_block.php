<?php
/**
 * action_block.php — SECURITY: Admin-only, POST-only, prepared statements.
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
 */
$report_id = (int) ($_POST['report_id'] ?? 0);
$user_id   = (int) ($_POST['user_id'] ?? 0);
// SECURITY: reason sanitised with htmlspecialchars on output; stored raw here
$reason    = trim($_POST['reason'] ?? '');
$back      = url('admin-users') . '?tab=blocked';

if ((!$report_id && !$user_id) || $reason === '') {
    header('Location: ' . $back . '&error=missing');
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

// An admin must not be able to block themselves out of the panel.
if ($user_id === (int)($_SESSION['user_id'] ?? 0)) {
    header('Location: ' . $back . '&error=self');
    exit;
}

if ($user_id) {

    $s1 = $con->prepare("INSERT INTO blocks (user_id, reason, blocked_at) VALUES (?, ?, NOW())");
    $s1->bind_param("is", $user_id, $reason);
    $s1->execute();
    $s1->close();

    $s2 = $con->prepare("UPDATE users SET status = 'blocked' WHERE user_id = ?");
    $s2->bind_param("i", $user_id);
    $s2->execute();
    $s2->close();

    if ($report_id) {
        $s3 = $con->prepare("UPDATE reports SET status = 'resolved', updated_at = NOW() WHERE report_id = ?");
        $s3->bind_param("i", $report_id);
        $s3->execute();
        $s3->close();
    }

    // Notify the blocked user, with the reason that was recorded.
    NotificationService::accountBlocked($con, $user_id, $reason);

    // The reason itself is kept in `blocks`; the log says who and when.
    pc_admin_log('blocked ' . pc_user_name($con, $user_id) . ($report_id ? ' from report #' . $report_id : ''));
}

pc_flash('success', 'Account blocked. They are signed out on their next request.', 'Blocked');
header('Location: ' . $back);
exit;
