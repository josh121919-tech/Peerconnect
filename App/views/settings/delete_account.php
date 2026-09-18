<?php
session_start();
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    echo json_encode(['success' => false, 'message' => 'Invalid request. Please refresh and try again.']);
    exit;
}

if (!rate_limit('delete_account_' . $_SESSION['user_id'], 3, 300)) {
    echo json_encode(['success' => false, 'message' => 'Too many attempts. Please try again later.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// SECURITY: confirm intent by typing the account's own email back — not a
// password, since Google-authenticated accounts never have a real, known
// password (google-login.php gives them a random one at signup), which
// would otherwise permanently lock those users out of ever deleting their
// own account.
$confirmEmail = is_string($_POST['confirm_email'] ?? null) ? trim($_POST['confirm_email']) : '';
$sessionEmail = trim($_SESSION['email'] ?? '');
if ($confirmEmail === '' || $sessionEmail === '' || strcasecmp($confirmEmail, $sessionEmail) !== 0) {
    echo json_encode(['success' => false, 'message' => 'Email confirmation did not match.']);
    exit;
}

// Read before anything changes: the sessions still ahead (so the notices can
// still name this person) and the application documents to delete.
$ahead     = AccountClosureService::sessionsAhead($con, $user_id);
$documents = VerificationRepository::filesOf($con, $user_id);
$oldEmail  = (string)(UserRepository::email($con, $user_id) ?? '');

// The activity log files entries under the email address. Once this account
// lets go of its address someone else may sign up with it, and must not find
// this account's history in their own Settings; it is refiled under a label
// that still ties it to the account for admins.
$logLabel = 'deleted-account-' . $user_id;

$con->begin_transaction();
try {
    // Revoke access the same way an admin block already does — reuses every
    // existing "blocked users can't log in / can't access pages" check
    // app-wide instead of introducing a new status value.
    ModerationRepository::recordBlock($con, $user_id, 'Account deleted by user request.');

    // No email, no username and no name: the account reads as "Deleted User"
    // wherever other people's sessions, messages and reviews still show it.
    UserRepository::closeDeletedAccount($con, $user_id);

    // Clear identifying profile and application details; leave
    // session/feedback/badge history intact so other users' records (a
    // mentor's earned rating, a session history) aren't corrupted by this
    // account's deletion.
    ProfileRepository::scrubForDeletedAccount($con, $user_id);
    VerificationRepository::scrubForDeletedAccount($con, $user_id);

    // "Remember me" on any device stops working.
    RememberService::forgetAllDevices($con, $user_id);

    if ($oldEmail !== '') {
        LogRepository::moveToEmail($con, $oldEmail, $logLabel);
    }

    $con->commit();
} catch (Throwable $e) {
    $con->rollback();
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
    exit;
}

// The account is gone either way from here; what follows tidies up after it.
logMe($logLabel, date('Y-m-d H:i:s'), 'account deleted by its owner');
try {
    // Sessions still ahead are called off, and the other person in each told.
    AccountClosureService::cancelSessions($con, $user_id, $ahead);
} catch (Throwable $e) {
    // The missed-session job still closes them; the deletion stands.
    error_log('Cancelling sessions of deleted account ' . $user_id . ' failed: ' . $e->getMessage());
}
// The student ID and registration form are not kept for a deleted account.
foreach ($documents as $document) {
    VerificationFiles::remove($document);
}

// Log the user out immediately.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

echo json_encode(['success' => true, 'message' => 'Your account has been deleted.', 'redirect' => url('welcomepage')]);
