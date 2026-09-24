<?php
/**
 * subscribe.php — a browser has been allowed to show notifications.
 *
 * The page sends what the Push API handed it: an endpoint belonging to the
 * browser vendor's push service, and the two keys that encrypt the payload so
 * that service cannot read it. All three come from the browser.
 *
 * Re-subscribing returns the same endpoint, and the page registers its service
 * worker on every load, so this is called far more often than a member adds a
 * device. The endpoint is unique in the table and the write updates in place.
 *
 * SECURITY: session + CSRF, and the subscription is stored against the signed
 * in account only — nothing in the request says whose it is.
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

$user_id = (int)$_SESSION['user_id'];

// A device that keeps re-registering should not be able to hammer the table.
if (!rate_limit('push_sub_' . $user_id, 30, 300)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'too many requests']);
    exit;
}

$str = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';

$endpoint = $str('endpoint');
$p256dh   = $str('p256dh');
$auth     = $str('auth');

/*
 * Only ever an https endpoint from a push service. Without this the table
 * becomes somewhere to store arbitrary text, and the sender becomes something
 * that can be pointed at any URL somebody likes.
 */
if ($endpoint === '' || $p256dh === '' || $auth === ''
    || !filter_var($endpoint, FILTER_VALIDATE_URL)
    || !str_starts_with($endpoint, 'https://')
    || mb_strlen($endpoint) > 500
    || mb_strlen($p256dh) > 255
    || mb_strlen($auth) > 255) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'that subscription does not look valid']);
    exit;
}

if (!PushSubscriptionRepository::enabled($con)) {
    // The migration has not been run here yet. Not an error the member can do
    // anything about, and everything still works without it.
    echo json_encode(['ok' => false, 'error' => 'device notifications are not set up on this server']);
    exit;
}

$agent = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
PushSubscriptionRepository::save($con, $user_id, $endpoint, $p256dh, $auth, $agent);

echo json_encode([
    'ok'      => true,
    'devices' => PushSubscriptionRepository::countForUser($con, $user_id),
]);
