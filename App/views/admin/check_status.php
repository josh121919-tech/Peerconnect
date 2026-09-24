<?php

/**
 * admin/check_status.php — polled by the administrator waiting screen, so the
 * page moves on the moment the owner decides.
 *
 * The role comes from users.role, never from the verification row. Both the
 * member and administrator applications live in the same table, and reading
 * the role from there would let an approved application of any kind hand out
 * whatever role this endpoint happened to be named after. This one only
 * re-reads what is already true, so a freshly approved account picks it up
 * without signing out and back in.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . "/../db.php";

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'unknown']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$row     = VerificationRepository::statusWithRole($con, $user_id);

$status = $row['status'] ?? 'none';
$role   = $row['role']   ?? '';

if ($status === 'approved' && $role !== '') {
    $_SESSION['role'] = $role;
}

$dashboards = [
    'mentor' => url('mentor-dashboard'),
    'mentee' => url('mentee-dashboard'),
    'admin'  => url('admin-dashboard'),
];

echo json_encode([
    'status'          => $status,
    'redirect'        => $dashboards[$role] ?? url('welcomepage'),
    // Rejected administrators come back to their own form to correct it.
    'reject_redirect' => url('admin-verification'),
]);
exit;
