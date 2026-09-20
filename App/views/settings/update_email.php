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

if (!rate_limit('update_email_' . $_SESSION['user_id'], 5, 300)) {
    echo json_encode(['success' => false, 'message' => 'Too many attempts. Please try again later.']);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
// A field sent as a list counts as missing.
$new_email = is_string($_POST['new_email'] ?? null) ? trim($_POST['new_email']) : '';
$password  = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}
// Sign-up allows 100 characters, and the activity log files entries under
// the address in a column of that size.
if (strlen($new_email) > 100) {
    echo json_encode(['success' => false, 'message' => 'That email address is too long.']);
    exit;
}

if ($password === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter your current password.']);
    exit;
}

$row = PasswordRepository::forUser($con, $user_id);

if (!$row) {
    // An account made with Google has no password to confirm the change with.
    echo json_encode(['success' => false, 'message' => 'This account has no password yet. Use Forgot password to add one, then change your email here.']);
    exit;
}

if (!password_verify($password, $row['password_hash'])) {
    echo json_encode(['success' => false, 'message' => 'Incorrect password.']);
    exit;
}

// Taken by anyone else? This user's own current address is excluded.
if (UserRepository::emailTaken($con, $new_email, $user_id)) {
    echo json_encode(['success' => false, 'message' => 'That email is already in use.']);
    exit;
}

$old_email = (string)(UserRepository::email($con, $user_id) ?? '');

$con->begin_transaction();
try {
    UserRepository::setEmail($con, $user_id, $new_email);

    // The activity log files entries under the address. Moving them keeps
    // this account's history (sign-ins, password changes) with it, rather
    // than behind under an address it no longer has.
    if ($old_email !== '' && strcasecmp($old_email, $new_email) !== 0) {
        LogRepository::moveToEmail($con, $old_email, $new_email);
    }

    $con->commit();
} catch (Exception $e) {
    $con->rollback();
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
    exit;
}

$_SESSION['email'] = $new_email;

$message = 'Email updated.';
if ($old_email !== '' && strcasecmp($old_email, $new_email) !== 0) {
    logMe($new_email, date('Y-m-d H:i:s'), 'email changed from ' . $old_email);

    /*
     * A new address is an unproved address. Clearing the stamp re-arms the
     * confirmation stage, which also makes Settings the way out of a typo:
     * somebody who registered as "jhon@" instead of "john@" can sign in,
     * correct it here and get a fresh letter, rather than being stuck behind
     * a link that will never arrive.
     */
    if (EmailVerificationRepository::migrated($con)) {
        UserRepository::clearEmailConfirmation($con, $user_id);
        $sent = EmailVerificationService::send($con, $user_id);
        if ($sent['sent']) {
            $message = 'Email updated. We have sent a confirmation link to ' . $new_email . '.';
        } elseif ($sent['throttled']) {
            $message = 'Email updated, but too many confirmation links have been requested just now.'
                . ' Wait a few minutes and use Resend.';
        } elseif (!$sent['skipped']) {
            $message = 'Email updated, but the confirmation link could not be sent. Please use Resend.';
        }
    }
}
echo json_encode(['success' => true, 'message' => $message, 'email' => $new_email]);
