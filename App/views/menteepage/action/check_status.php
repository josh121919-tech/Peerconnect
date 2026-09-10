<?php

/**
 * check_status.php — polled by the verification screen while an application
 * is pending, so the page can move the user on the moment an admin approves.
 *
 * SECURITY: this used to set $_SESSION['role'] to a hardcoded literal
 * whenever the caller's verification row said 'approved'. Both roles store
 * their applications in the same `user_verifications` table, so an approved
 * MENTEE could call the mentor copy of this endpoint and be handed the mentor
 * role — a plain GET, no CSRF, full access to accepting requests and
 * publishing availability. The role now comes from `users.role`, which only
 * an admin can change; this endpoint just re-reads it so a freshly approved
 * account picks it up without signing out.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . "/../../db.php";

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'unknown']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$stmt = $con->prepare("
    SELECT u.role, v.status
    FROM users u
    LEFT JOIN user_verifications v ON v.user_id = u.user_id
    WHERE u.user_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$status = $row['status'] ?? 'none';
$role   = $row['role']   ?? '';

if ($status === 'approved' && $role !== '') {
    $_SESSION['role'] = $role;
}

// Send them to their own dashboard, whichever role they actually hold.
$dashboards = [
    'mentor' => url('mentor-dashboard'),
    'mentee' => url('mentee-dashboard'),
    'admin'  => url('admin-dashboard'),
];

echo json_encode([
    'status'          => $status,
    'redirect'        => $dashboards[$role] ?? url('welcomepage'),
    'reject_redirect' => '/case/case/140204565c663d348ed7ae748f5a5802',
]);
exit;
