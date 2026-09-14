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

// Taken by anyone else? This user's own current address is excluded.
$dupCheck = $con->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
$dupCheck->bind_param("si", $new_email, $user_id);
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

    $con->commit();
} catch (Exception $e) {
    $con->rollback();
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
    exit;
}

$_SESSION['email'] = $new_email;
echo json_encode(['success' => true, 'message' => 'Email updated.', 'email' => $new_email]);
