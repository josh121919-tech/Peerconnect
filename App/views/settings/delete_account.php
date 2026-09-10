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
$confirmEmail = trim($_POST['confirm_email'] ?? '');
$sessionEmail = trim($_SESSION['email'] ?? '');
if ($confirmEmail === '' || $sessionEmail === '' || strcasecmp($confirmEmail, $sessionEmail) !== 0) {
    echo json_encode(['success' => false, 'message' => 'Email confirmation did not match.']);
    exit;
}

$con->begin_transaction();
try {
    // Revoke access the same way an admin block already does — reuses every
    // existing "blocked users can't log in / can't access pages" check
    // app-wide instead of introducing a new status value.
    $reason = 'Account deleted by user request.';
    $s1 = $con->prepare("INSERT INTO blocks (user_id, reason, blocked_at) VALUES (?, ?, NOW())");
    $s1->bind_param("is", $user_id, $reason);
    $s1->execute();
    $s1->close();

    $s2 = $con->prepare("UPDATE users SET status = 'blocked', email = NULL WHERE user_id = ?");
    $s2->bind_param("i", $user_id);
    $s2->execute();
    $s2->close();

    $s3 = $con->prepare("DELETE FROM emails WHERE user_id = ?");
    $s3->bind_param("i", $user_id);
    $s3->execute();
    $s3->close();

    // Clear identifying profile info; leave session/feedback/badge history
    // intact so other users' records (a mentor's earned rating, a session
    // history) aren't corrupted by this account's deletion.
    $s4 = $con->prepare("UPDATE profile SET full_name = 'Deleted User', student_id = NULL, course = NULL, year_level = NULL, club = NULL, profile_image = NULL WHERE user_id = ?");
    $s4->bind_param("i", $user_id);
    $s4->execute();
    $s4->close();

    $con->commit();
} catch (Throwable $e) {
    $con->rollback();
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
    exit;
}

// Log the user out immediately.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

echo json_encode(['success' => true, 'message' => 'Your account has been deleted.', 'redirect' => url('welcomepage')]);
