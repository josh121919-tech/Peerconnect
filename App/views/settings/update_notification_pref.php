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
$enabled = ($_POST['enabled'] ?? '') === '1';

if (!in_array($pref, PreferenceRepository::NOTIFICATION_KEYS, true)) {
    echo json_encode(['success' => false, 'message' => 'Unknown preference.']);
    exit;
}

// The account's row is created with the defaults first, so only the one
// switch that changed has to be sent.
PreferenceRepository::setNotification($con, $user_id, $pref, $enabled);

echo json_encode(['success' => true, 'message' => 'Saved.']);
