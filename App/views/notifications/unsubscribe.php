<?php
/**
 * unsubscribe.php — this browser should stop receiving notifications.
 *
 * Turning it off in the app has to remove the row here as well as drop the
 * browser's own subscription: leaving it behind means we keep posting to a
 * device whose owner has said no, and the push service keeps delivering.
 *
 * SECURITY: session + CSRF, and scoped to the signed in account — an endpoint
 * is not a secret, and without the scope one member could unsubscribe another.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../../config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not signed in']);
    exit;
}
if (!verify_csrf()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$user_id  = (int)$_SESSION['user_id'];
$endpoint = is_string($_POST['endpoint'] ?? null) ? trim($_POST['endpoint']) : '';

if ($endpoint === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'no endpoint given']);
    exit;
}

PushSubscriptionRepository::forget($con, $user_id, $endpoint);

echo json_encode([
    'ok'      => true,
    'devices' => PushSubscriptionRepository::countForUser($con, $user_id),
]);
