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
if ($username !== '') {
    $dup = $con->prepare("SELECT 1 FROM users WHERE username = ? AND user_id <> ?");
    $dup->bind_param("si", $username, $user_id);
    $dup->execute();
    $taken = $dup->get_result()->num_rows > 0;
    $dup->close();
    if ($taken) {
        echo json_encode(['success' => false, 'message' => 'That username is already taken.']);
        exit;
    }
}

// Split the single name field back into the two columns users actually has.
$parts     = preg_split('/\s+/', $full_name, 2);
$firstname = $parts[0];
$lastname  = $parts[1] ?? '';

$con->begin_transaction();
try {
    $u = $con->prepare("UPDATE users SET firstname = ?, lastname = ?, username = ? WHERE user_id = ?");
    $uname = $username !== '' ? $username : null;
    $u->bind_param("sssi", $firstname, $lastname, $uname, $user_id);
    $u->execute();
    $u->close();

    // profile may not have a row yet for older accounts.
    $p = $con->prepare("
        INSERT INTO profile (user_id, full_name, phone, location, birthdate, bio, visibility)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            full_name  = VALUES(full_name),
            phone      = VALUES(phone),
            location   = VALUES(location),
            birthdate  = VALUES(birthdate),
            bio        = VALUES(bio),
            visibility = VALUES(visibility)
    ");
    $p->bind_param("issssss", $user_id, $full_name, $phone, $location, $birthdate, $bio, $visibility);
    $p->execute();
    $p->close();

    $con->commit();
} catch (Throwable $e) {
    $con->rollback();
    echo json_encode(['success' => false, 'message' => 'Could not save your details. Please try again.']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Your account details have been saved.']);
