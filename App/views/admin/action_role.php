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

if (!verify_csrf()) {
    http_response_code(403);
    exit('CSRF token mismatch.');
}

// A field sent as a list counts as missing: (int) of a list is 1, which
// would have picked account #1.
$raw     = is_string($_POST['user_id'] ?? null) ? trim($_POST['user_id']) : '';
$user_id = ctype_digit($raw) ? (int)$raw : 0;
$wanted  = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
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

$target = UserRepository::moderationTarget($con, $user_id);
if (!$target) {
    pc_flash('error', 'That account could not be found, so nothing changed.', 'Role not set');
    header('Location: ' . url('admin-users'));
    exit;
}
if (ModerationService::isDeleted($target)) {
    pc_flash('error', 'That account was deleted by its owner, so it was left alone.', 'Role not set');
    header('Location: ' . $back);
    exit;
}

// The WHERE clause is the real guard: an account that already has a role is
// not matched, so a stale form or a replayed POST cannot overwrite one.
if (UserRepository::claimRole($con, $user_id, $wanted) < 1) {
    pc_flash('error', 'That account already has a role, so it was left alone.', 'Role not set');
    header('Location: ' . $back);
    exit;
}

pc_admin_log('set the role of ' . pc_user_name($con, $user_id) . ' to ' . $wanted);

pc_flash('success', 'Role set to ' . ucfirst($wanted) . '. They can now reach their dashboard.', 'Role set');
header('Location: ' . $back);
exit;
