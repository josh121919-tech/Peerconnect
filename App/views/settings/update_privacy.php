<?php
// Save one Data Privacy toggle. Each of these actually changes what the rest
// of the app does — see the notes on each key.
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

$allowed = [
    // Dashboard "Recommended for You" and the Matching page.
    'personalized_recommendations',
    // Whether this mentor appears on the Leaderboard.
    'share_activity',
    // Whether Google Calendar may be connected / synced.
    'third_party_integrations',
];

$key = (string)($_POST['key'] ?? '');
if (!in_array($key, $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Unknown privacy setting.']);
    exit;
}
$value = ($_POST['value'] ?? '0') === '1' ? 1 : 0;

// Column name comes from the whitelist above, never from the request.
$stmt = $con->prepare("
    INSERT INTO privacy_settings (user_id, `$key`) VALUES (?, ?)
    ON DUPLICATE KEY UPDATE `$key` = VALUES(`$key`)
");
$stmt->bind_param("ii", $user_id, $value);
$stmt->execute();
$stmt->close();

// Turning off third-party integrations has to actually disconnect Google,
// otherwise the switch would be a label rather than a setting.
$extra = '';
if ($key === 'third_party_integrations' && $value === 0) {
    try {
        require_once __DIR__ . '/../../services/GoogleCalendarService.php';
        if (GoogleCalendarService::isConnected($con, $user_id)) {
            GoogleCalendarService::disconnect($con, $user_id);
            $extra = ' Your Google Calendar has been disconnected.';
        }
    } catch (Throwable $e) {
        // The setting is saved either way.
    }
}

echo json_encode(['success' => true, 'message' => 'Privacy setting updated.' . $extra]);
