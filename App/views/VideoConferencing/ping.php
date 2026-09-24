<?php

/**
 * ping.php — the call's heartbeat.
 *
 * room.php posts here every 30 seconds while the call page is open, and that
 * is the only way the server learns somebody is still in a session: the call
 * itself runs in an embedded frame it cannot see, and leaving is reported by
 * a beacon that a crash, a closed laptop or a dropped line never sends.
 *
 * Presence is what decides whether a session counts as completed, so this
 * endpoint is deliberately dull: it says "I am here now" and nothing else. It
 * cannot change a status, cannot close a session, and cannot record anybody
 * but the person posting it.
 *
 * The gap between beats is what makes an unclean exit cost only the last beat
 * rather than the rest of the session — see SessionRepository::openPresence(),
 * which closes a silent interval at its last heartbeat instead of extending it.
 */

date_default_timezone_set('Asia/Manila');
session_start();
include __DIR__ . "/../db.php";

header('Content-Type: application/json');

/** Every refusal looks the same to the page: it retries on the next beat regardless. */
function ping_no(string $why): void
{
    echo json_encode(['ok' => false, 'error' => $why]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    ping_no('not logged in');
}

$user_id = (int)$_SESSION['user_id'];

$body       = json_decode(file_get_contents('php://input'), true);
$session_id = (int)($body['session_id'] ?? 0);

if (!verify_csrf_token($body['csrf_token'] ?? null)) {
    ping_no('invalid csrf token');
}

// A beat every 30 seconds is 2 a minute. This leaves room for a retry and a
// second tab without letting a loose script write to the table in a loop.
if (!rate_limit('video_ping_' . $user_id, 20, 60)) {
    ping_no('too many requests');
}

if (!$session_id) {
    ping_no('missing session_id');
}

// The same membership check the leave endpoint makes: this has to be a session
// the poster is actually in, and one that can still be acted on.
$row = SessionRepository::approvedForParticipant($con, $session_id, $user_id);
if (!$row) {
    ping_no('session not found or already ended');
}

$startTs = (new DateTime($row['session_date'], new DateTimeZone('Asia/Manila')))->getTimestamp();
$endTs   = $startTs + ((int)$row['duration'] * 60);
$now     = time();

// Before the room opens, and after the session is over, there is nothing to
// record. Without the upper bound a tab left open overnight would keep
// writing, and presence is meant to describe the session, not the tab.
if ($now < $startTs - (SessionRepository::JOIN_WINDOW_MINUTES * 60)) {
    ping_no('session has not started');
}
if ($now > $endTs) {
    ping_no('session is over');
}

$role = ((int)$row['mentor_id'] === $user_id) ? 'mentor' : 'mentee';
SessionRepository::openPresence($con, $session_id, $user_id, $role);

echo json_encode(['ok' => true]);
