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

$user_id = (int)$_SESSION['user_id'];
$pref    = $_POST['pref'] ?? '';
$enabled = ($_POST['enabled'] ?? '') === '1' ? 1 : 0;

$allowed = ['session_requests', 'session_reminders', 'feedback_received', 'messages'];
if (!in_array($pref, $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Unknown preference.']);
    exit;
}

// Seed a default row first (idempotent), then update just the one toggle —
// avoids needing to know/send the other three current values from the client.
$seed = $con->prepare("INSERT IGNORE INTO notification_preferences (user_id) VALUES (?)");
$seed->bind_param("i", $user_id);
$seed->execute();
$seed->close();

$sql = "UPDATE notification_preferences SET `$pref` = ? WHERE user_id = ?";
$stmt = $con->prepare($sql);
$stmt->bind_param("ii", $enabled, $user_id);
$ok = $stmt->execute();
$stmt->close();

echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Saved.' : 'Could not save preference.']);
