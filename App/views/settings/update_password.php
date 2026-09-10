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
$current_password   = $_POST['current_password'] ?? '';
$new_password       = $_POST['new_password'] ?? '';
$confirm_new_password = $_POST['confirm_new_password'] ?? '';

if ($current_password === '' || $new_password === '' || $confirm_new_password === '') {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

if ($new_password !== $confirm_new_password) {
    echo json_encode(['success' => false, 'message' => 'New passwords do not match.']);
    exit;
}

if (!preg_match("/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@#$%^&*!?])[A-Za-z\d@#$%^&*!?]{8,20}$/", $new_password)) {
    echo json_encode(['success' => false, 'message' => 'Password must be 8-20 characters and include an uppercase letter, lowercase letter, number, and special character.']);
    exit;
}

$stmt = $con->prepare("SELECT password_id, password_hash FROM passwords WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || !password_verify($current_password, $row['password_hash'])) {
    echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
    exit;
}

if (password_verify($new_password, $row['password_hash'])) {
    echo json_encode(['success' => false, 'message' => 'New password must be different from your current password.']);
    exit;
}

$hashed = password_hash($new_password, PASSWORD_BCRYPT);
$upd = $con->prepare("UPDATE passwords SET password_hash = ? WHERE password_id = ?");
$upd->bind_param("si", $hashed, $row['password_id']);
$upd->execute();
$upd->close();

// Recorded in the same log the sign-in flow writes to, so Settings → Security
// can show "last changed" and list it under Recent Security Activity.
try {
    $emailRow = $con->query("SELECT email FROM users WHERE user_id = " . (int)$user_id)->fetch_assoc();
    if ($emailRow) {
        $lg = $con->prepare("INSERT INTO logs (email, activity, log_date) VALUES (?, 'password changed', NOW())");
        $lg->bind_param("s", $emailRow['email']);
        $lg->execute();
        $lg->close();
    }
} catch (Throwable $e) {
    // Logging must never fail the password change itself.
}

echo json_encode(['success' => true, 'message' => 'Password updated.']);
