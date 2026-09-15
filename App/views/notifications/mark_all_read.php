<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../../config/db.php';

/*
 * Called two ways: by the member-facing bell over fetch(), which wants JSON,
 * and by the admin bell's plain form, which wants to end up back on the page
 * it was pressed from. Answering JSON to a form post would leave the admin
 * staring at `{"ok":true}`, so the reply follows the request.
 */
$wants_json = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
    || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

$fail = function (int $code, string $why) use ($wants_json) {
    http_response_code($code);
    if ($wants_json) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $why]);
    } else {
        echo htmlspecialchars($why);
    }
    exit;
};

if (!isset($_SESSION['user_id'])) $fail(401, 'Not signed in.');

/*
 * verify_csrf() rejects an empty session token too: hash_equals('', '') is
 * true, so a request arriving before any page had generated a token would
 * otherwise pass the check by sending nothing at all.
 */
if (!verify_csrf()) {
    $fail(403, 'Security token mismatch.');
}

$uid  = (int)$_SESSION['user_id'];
$stmt = $con->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0");
$stmt->bind_param("i", $uid);
$stmt->execute();
$stmt->close();

if ($wants_json) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// Same-origin referer only, so this cannot be used to bounce someone offsite.
$back = $_SERVER['HTTP_REFERER'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($back === '' || (parse_url($back, PHP_URL_HOST) !== null && parse_url($back, PHP_URL_HOST) !== $host)) {
    // No usable referer: send them to their own home, not someone else's.
    $back = match ($_SESSION['role'] ?? '') {
        'admin'  => url('admin-dashboard'),
        'mentor' => url('mentor-dashboard'),
        default  => url('mentee-dashboard'),
    };
}
header('Location: ' . $back);
exit;
