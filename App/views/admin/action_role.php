<?php
/**
 * action_role.php — sets the role on an account that has none.
 *
 * SECURITY: Admin-only, POST-only, CSRF-checked, prepared statements.
 *
 * Deliberately a repair tool, not a role switcher. It writes a role only when
 * the account currently has none, because changing an established role is a
 * data problem rather than a settings one: a mentor turned mentee still owns
 * availability rows, session requests and feedback that only make sense for a
 * mentor, and nothing here migrates them. 'admin' is never assignable —
 * admin accounts are created through admin/signup.php.
 *
 * Accounts with an empty role exist because two of the three sign-up paths
 * could create one and nothing offered a way to choose afterwards. The member
 * can now fix it themselves at onboarding/role_choice.php; this is the same
 * repair from the admin side, for someone who cannot or will not sign in.
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

$user_id = (int)($_POST['user_id'] ?? 0);
$wanted  = (string)($_POST['role'] ?? '');
$back    = url('admin-user') . '?id=' . $user_id;

if ($user_id < 1 || !in_array($wanted, ['mentee', 'mentor'], true)) {
    pc_flash('error', 'Pick either Mentee or Mentor.', 'Role not set');
    header('Location: ' . $back);
    exit;
}

// An admin editing their own row cannot demote themselves out of the panel.
if ($user_id === (int)($_SESSION['user_id'] ?? 0)) {
    pc_flash('error', 'You cannot change your own role.', 'Role not set');
    header('Location: ' . $back);
    exit;
}

// The WHERE clause is the real guard: an account that already has a role is
// not matched, so a stale form or a replayed POST cannot overwrite one.
$st = $con->prepare("UPDATE users SET role = ? WHERE user_id = ? AND (role IS NULL OR role = '')");
$st->bind_param("si", $wanted, $user_id);
$st->execute();
$changed = $st->affected_rows;
$st->close();

if ($changed < 1) {
    pc_flash('error', 'That account already has a role, so it was left alone.', 'Role not set');
    header('Location: ' . $back);
    exit;
}

logMe($_SESSION['email'] ?? '', date('Y-m-d H:i:s'), "set role of user {$user_id} to {$wanted}");

pc_flash('success', 'Role set to ' . ucfirst($wanted) . '. They can now reach their dashboard.', 'Role set');
header('Location: ' . $back);
exit;
