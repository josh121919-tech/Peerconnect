<?php
/**
 * action_verify.php — an admin approves or rejects a verification application.
 * SECURITY: admin-only, POST-only, CSRF-checked, prepared statements.
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
$field  = fn(string $name): string => is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
$vid    = (int)$field('verification_id');
$raw    = $field('action');
$notes  = trim($field('admin_notes'));

$action = in_array($raw, ['approve', 'reject'], true) ? $raw : null;

if (!$vid || !$action) {
    header('Location: ' . url('admin-users') . '?tab=pending&error=missing');
    exit;
}

$status = ($action === 'approve') ? 'approved' : 'rejected';

// Only an application still waiting is decided. Another admin may have got
// there first, or this page may have been open since before the decision;
// either way the earlier decision stands.
if (VerificationRepository::decide($con, $vid, $status, $notes) < 1) {
    if (VerificationRepository::ownerOf($con, $vid) === null) {
        pc_flash('warning', 'That application could not be found, so nothing changed.', 'Not found');
    } else {
        pc_flash('warning', 'That application was already decided, so nothing changed.', 'Already decided');
    }
    header('Location: ' . url('admin-users') . '?tab=pending');
    exit;
}

$uid = VerificationRepository::ownerOf($con, $vid);

if ($uid !== null) {
    /*
     * Each role applies on its own page and goes back to its own. This used to
     * be a two-way choice on "is it a mentor", which sent an approved
     * administrator to the mentee dashboard and a rejected one to the mentee
     * form — neither of which they can open.
     */
    $applicantRole = (string)UserRepository::role($con, $uid);
    $home = [
        'mentor' => 'mentor-dashboard',
        'admin'  => 'admin-dashboard',
    ][$applicantRole] ?? 'mentee-dashboard';
    $form = [
        'mentor' => 'mentor-verification',
        'admin'  => 'admin-verification',
    ][$applicantRole] ?? 'mentee-verification';

    if ($action === 'approve') {
        // Verified, and nothing else: an approval does not lift a block or a
        // restriction the account is under.
        UserRepository::markVerified($con, $uid);

        NotificationService::verificationApproved($con, $uid, url($home));
    } else {
        NotificationService::verificationRejected($con, $uid, $notes, url($form));
    }

    pc_admin_log(($action === 'approve' ? 'approved' : 'rejected') . ' the verification of ' . pc_user_name($con, $uid));
}

// Verification lives inside User Management now, so come back to its queue.
if ($action === 'approve') {
    pc_flash('success', 'Application approved — the account is now verified.', 'Approved');
} else {
    pc_flash('success', 'Application rejected, and the applicant has been told why.', 'Rejected');
}
header('Location: ' . url('admin-users') . '?tab=pending');
exit;
