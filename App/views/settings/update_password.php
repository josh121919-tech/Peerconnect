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

if (!rate_limit('update_password_' . $_SESSION['user_id'], 5, 300)) {
    echo json_encode(['success' => false, 'message' => 'Too many attempts. Please try again later.']);
    exit;
}

$user_id            = (int)$_SESSION['user_id'];
// A field sent as a list counts as missing.
$current_password   = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
$new_password       = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
$confirm_new_password = is_string($_POST['confirm_new_password'] ?? null) ? $_POST['confirm_new_password'] : '';

if ($current_password === '' || $new_password === '' || $confirm_new_password === '') {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

if ($new_password !== $confirm_new_password) {
    echo json_encode(['success' => false, 'message' => 'New passwords do not match.']);
    exit;
}

$problem = PasswordPolicy::problem($new_password, PasswordPolicy::minLength($con));
if ($problem !== null) {
    echo json_encode(['success' => false, 'message' => $problem]);
    exit;
}

$row = PasswordRepository::forUser($con, $user_id);

if (!$row) {
    // An account made with Google has no password to change yet.
    echo json_encode(['success' => false, 'message' => 'This account has no password yet. Use Forgot password to add one.']);
    exit;
}

if (!password_verify($current_password, $row['password_hash'])) {
    echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
    exit;
}

if (password_verify($new_password, $row['password_hash'])) {
    echo json_encode(['success' => false, 'message' => 'New password must be different from your current password.']);
    exit;
}

PasswordRepository::setHash($con, (int)$row['password_id'], password_hash($new_password, PASSWORD_BCRYPT));

// "Remember me" on any other device stops working, as it does after a
// password reset; this browser stays signed in. (A device that is signed in
// right now keeps its current session until it ends — nothing checks a
// session against the password, here or after a reset.)
$signedOut = RememberService::forgetOtherDevices($con, (int)$user_id);

// Recorded in the same log the sign-in flow writes to, so Settings → Security
// can show "last changed" and list it under Recent Security Activity.
try {
    $email = UserRepository::email($con, $user_id);
    if ($email !== null) {
        LogRepository::add($con, $email, 'password changed');
    }
} catch (Throwable $e) {
    // Logging must never fail the password change itself.
}

echo json_encode(['success' => true, 'message' => $signedOut > 0
    ? 'Password updated. Other devices where you chose "Remember me" will ask you to sign in again.'
    : 'Password updated.']);
