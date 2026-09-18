<?php
// Save the Account tab: profile details that live on users + profile.
// Email and password are deliberately not handled here — they keep their own
// endpoints (update_email.php / update_password.php) with their own checks.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not signed in.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh and try again.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// A field sent as a list counts as missing, never as the text "Array".
$field     = fn(string $name): string => is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
$full_name = trim(strip_tags($field('full_name')));
$username  = trim(strip_tags($field('username')));
$phone     = trim(strip_tags($field('phone')));
$location  = trim(strip_tags($field('location')));
$birthdate = trim($field('birthdate'));
$bio       = trim(strip_tags($field('bio')));
$visibility = in_array($_POST['visibility'] ?? '', ['everyone', 'mentors', 'private'], true)
    ? $_POST['visibility'] : 'everyone';

// ── Validate ─────────────────────────────────────────────────────────────
if ($full_name === '') {
    echo json_encode(['success' => false, 'message' => 'Your full name cannot be empty.']);
    exit;
}
if (mb_strlen($full_name) > 100) {
    echo json_encode(['success' => false, 'message' => 'That name is too long (100 characters max).']);
    exit;
}
// The account keeps a first name (the first word) and a last name (the rest),
// each in a column of 50 characters, which used to cut a longer one short.
$parts     = preg_split('/\s+/', $full_name, 2);
$firstname = $parts[0];
$lastname  = $parts[1] ?? '';
if (mb_strlen($firstname) > UserRepository::NAME_MAX || mb_strlen($lastname) > UserRepository::NAME_MAX) {
    echo json_encode(['success' => false, 'message' => 'Your first name and your last name can each be up to ' . UserRepository::NAME_MAX . ' characters.']);
    exit;
}
if ($username !== '' && !preg_match('/^[a-zA-Z0-9._]{3,30}$/', $username)) {
    echo json_encode(['success' => false, 'message' => 'Usernames are 3–30 characters, letters, numbers, dots and underscores only.']);
    exit;
}
if ($phone !== '' && !preg_match('/^[0-9 +()\-]{7,25}$/', $phone)) {
    echo json_encode(['success' => false, 'message' => 'That phone number does not look right.']);
    exit;
}
if ($birthdate !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $birthdate);
    if (!$d || $d->format('Y-m-d') !== $birthdate) {
        echo json_encode(['success' => false, 'message' => 'Please give your date of birth as a real date.']);
        exit;
    }
    if ($d > new DateTime('now')) {
        echo json_encode(['success' => false, 'message' => 'Your date of birth cannot be in the future.']);
        exit;
    }
} else {
    $birthdate = null;
}
if (mb_strlen($bio) > 500) {
    echo json_encode(['success' => false, 'message' => 'Your bio is limited to 500 characters.']);
    exit;
}
if (mb_strlen($location) > 120) {
    echo json_encode(['success' => false, 'message' => 'That location is too long.']);
    exit;
}

// Usernames are unique across the platform.
if ($username !== '' && UserRepository::usernameTaken($con, $username, $user_id)) {
    echo json_encode(['success' => false, 'message' => 'That username is already taken.']);
    exit;
}

$con->begin_transaction();
try {
    UserRepository::saveNamesAndUsername($con, $user_id, $firstname, $lastname, $username !== '' ? $username : null);

    // profile may not have a row yet for older accounts.
    ProfileRepository::saveAccountDetails($con, $user_id, $full_name, $phone, $location, $birthdate, $bio, $visibility);

    $con->commit();
} catch (Throwable $e) {
    $con->rollback();
    echo json_encode(['success' => false, 'message' => 'Could not save your details. Please try again.']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Your account details have been saved.']);
