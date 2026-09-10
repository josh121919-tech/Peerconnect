<?php
/**
 * action_verify.php
 * SECURITY: Uses prepared statements exclusively — no raw string interpolation.
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

$vid    = (int)($_POST['verification_id'] ?? 0);
$raw    = $_POST['action'] ?? '';
$notes  = trim($_POST['admin_notes'] ?? '');

$action = in_array($raw, ['approve', 'reject']) ? $raw : null;

if (!$vid || !$action) {
    header('Location: ' . url('admin-users') . '?tab=pending&error=missing');
    exit;
}

$status = ($action === 'approve') ? 'approved' : 'rejected';

$stmt = $con->prepare("UPDATE user_verifications SET status = ?, admin_notes = ?, reviewed_at = NOW() WHERE verification_id = ?");
$stmt->bind_param("ssi", $status, $notes, $vid);
$stmt->execute();
$stmt->close();

// Fetch user_id for notification + status update
$fetch = $con->prepare("SELECT user_id FROM user_verifications WHERE verification_id = ?");
$fetch->bind_param("i", $vid);
$fetch->execute();
$v = $fetch->get_result()->fetch_assoc();
$fetch->close();

if ($v) {
    $uid = (int)$v['user_id'];

    // Fetch user role to determine redirect link
    $roleQ = $con->prepare("SELECT role FROM users WHERE user_id = ?");
    $roleQ->bind_param("i", $uid);
    $roleQ->execute();
    $roleRow = $roleQ->get_result()->fetch_assoc();
    $roleQ->close();

    if ($action === 'approve') {
        $upd = $con->prepare("UPDATE users SET verified = 1, status = 'active' WHERE user_id = ?");
        $upd->bind_param("i", $uid);
        $upd->execute();
        $upd->close();

        $dashLink = ($roleRow['role'] ?? '') === 'mentor' ? url('mentor-dashboard') : url('mentee-dashboard');
        NotificationService::verificationApproved($con, $uid, $dashLink);
    } else {
        NotificationService::verificationRejected($con, $uid, $notes, url('mentee-verification'));
    }
}

// Verification lives inside User Management now, so come back to its queue.
if ($action === 'approve') {
    pc_flash('success', 'Application approved — the account is now active.', 'Approved');
} else {
    pc_flash('success', 'Application rejected, and the applicant has been told why.', 'Rejected');
}
header('Location: ' . url('admin-users') . '?tab=pending');
exit;
