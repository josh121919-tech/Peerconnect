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
$new_email = trim($_POST['new_email'] ?? '');
$password  = $_POST['confirm_password'] ?? '';

if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

if ($password === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter your current password.']);
    exit;
}

$stmt = $con->prepare("SELECT password_hash FROM passwords WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || !password_verify($password, $row['password_hash'])) {
    echo json_encode(['success' => false, 'message' => 'Incorrect password.']);
    exit;
}

// Uniqueness check across both users.email and the legacy emails table,
// excluding this user's own current rows.
$dupCheck = $con->prepare("
    SELECT user_id FROM users WHERE email = ? AND user_id != ?
    UNION
    SELECT user_id FROM emails WHERE email = ? AND user_id != ?
");
$dupCheck->bind_param("sisi", $new_email, $user_id, $new_email, $user_id);
$dupCheck->execute();
$isDuplicate = $dupCheck->get_result()->num_rows > 0;
$dupCheck->close();

if ($isDuplicate) {
    echo json_encode(['success' => false, 'message' => 'That email is already in use.']);
    exit;
}

$con->begin_transaction();
try {
    $upd = $con->prepare("UPDATE users SET email = ? WHERE user_id = ?");
    $upd->bind_param("si", $new_email, $user_id);
    $upd->execute();
    $upd->close();

    // P4 migration keeps an `emails` row per user for legacy login lookups —
    // update it if present, otherwise create it, so login stays consistent.
    $chk = $con->prepare("SELECT email_id FROM emails WHERE user_id = ?");
    $chk->bind_param("i", $user_id);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($existing) {
        $updEmails = $con->prepare("UPDATE emails SET email = ? WHERE user_id = ?");
        $updEmails->bind_param("si", $new_email, $user_id);
        $updEmails->execute();
        $updEmails->close();
    } else {
        $insEmails = $con->prepare("INSERT INTO emails (user_id, email) VALUES (?, ?)");
        $insEmails->bind_param("is", $user_id, $new_email);
        $insEmails->execute();
        $insEmails->close();
    }

    $con->commit();
} catch (Exception $e) {
    $con->rollback();
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
    exit;
}

$_SESSION['email'] = $new_email;
echo json_encode(['success' => true, 'message' => 'Email updated.', 'email' => $new_email]);
