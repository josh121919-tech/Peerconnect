<?php
/**
 * end_session.php — somebody left the call.
 *
 * Reached by fetch when they press End & Leave, and by sendBeacon when they
 * close the tab. It closes their presence interval and then asks what that
 * makes of the session: past its scheduled end, settleAtEnd() decides from
 * how long each of them was actually there; still inside it, they can come
 * back, so this is only "not everyone is in the call at the moment".
 *
 * The old note here said completion happened in feedback.php instead. It does
 * not: that path now calls settleAtEnd() too, so there is one rule and one
 * place that writes the outcome.
 */
date_default_timezone_set('Asia/Manila');
session_start();
include __DIR__ . "/../db.php";
require_once __DIR__ . "/../../services/NotificationService.php";

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'not logged in']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'mentee';

$body       = json_decode(file_get_contents('php://input'), true);
$session_id = (int)($body['session_id'] ?? 0);

if (!verify_csrf_token($body['csrf_token'] ?? null)) {
    echo json_encode(['ok' => false, 'error' => 'invalid csrf token']);
    exit;
}

if (!rate_limit('video_end_' . $user_id, 10, 60)) {
    echo json_encode(['ok' => false, 'error' => 'too many requests']);
    exit;
}

if (!$session_id) {
    echo json_encode(['ok' => false, 'error' => 'missing session_id']);
    exit;
}

// Verify the user belongs to this session
$row = SessionRepository::approvedForParticipant($con, $session_id, $user_id);

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'session not found or already ended']);
    exit;
}

// A call can only be ended once it could have been joined: the room opens 15
// minutes before the start (room.php checks the same way). Without this, a
// mentor could post here directly and mark any approved session completed days
// before it happened, and a mentee could send "left the session" at any time.
$minutesUntilStart = ((new DateTime($row['session_date'], new DateTimeZone('Asia/Manila')))->getTimestamp() - time()) / 60;
if ($minutesUntilStart > SessionRepository::JOIN_WINDOW_MINUTES) {
    echo json_encode(['ok' => false, 'error' => 'session has not started']);
    exit;
}

/*
 * Leaving is recorded before anything is decided, because every decision
 * below reads it back. A session used to be closed the moment the mentor
 * walked out — two minutes in and it counted as finished — and this is where
 * that was done.
 */
SessionRepository::recordLeave($con, $session_id, $user_id);

// Closes the open presence interval at this moment. Whatever happens below,
// the time they were actually here is now on record — and settleAtEnd() two
// lines down reads it.
SessionRepository::closePresence($con, $session_id, $user_id);

$endTs  = (new DateTime($row['session_date'], new DateTimeZone('Asia/Manila')))->getTimestamp()
          + ((int)$row['duration'] * 60);
$isOver = time() >= $endTs;

/*
 * The mentor is the mentor of every booking in a group slot, so their leaving
 * bears on all of them. A mentee's leaving bears only on their own.
 */
$affected = ($role === 'mentor')
    ? SessionRepository::bookingIdsInSlotOf($con, $session_id)
    : [$session_id];

$outcome = null;
foreach ($affected as $bookingId) {
    if ($isOver) {
        // Ran to its scheduled end: settle it now rather than leaving it for
        // the 30-minute job, so the tabs are right as soon as they close the
        // tab. Same rule the job applies, from the same method.
        $settled = SessionRepository::settleAtEnd($con, $bookingId);
    } else {
        // Still inside the slot. They can come back, so this is not an
        // ending — it is "not everyone is in the call at the moment".
        $settled = SessionRepository::refreshLiveStatus($con, $bookingId);
    }
    if ($bookingId === $session_id) {
        $outcome = $settled;
    }
}

/*
 * One notice each, for this session.
 *
 * This endpoint runs every time somebody closes the tab or leaves the room,
 * and it used to notify on every one of them: a mentor who stepped out and
 * came back sent the mentee that many copies of the same sentence. Scoped
 * from the moment the call opened, so the next session between the same two
 * people starts with a clean slate.
 *
 * "Ended" and "left early" are different sentences, so a mentor who leaves
 * mid-session and again at the end still sends both — which is right, they
 * say different things.
 */
$since = date(
    'Y-m-d H:i:s',
    (new DateTime($row['session_date'], new DateTimeZone('Asia/Manila')))->getTimestamp()
        - (SessionRepository::JOIN_WINDOW_MINUTES * 60)
);

if ($role === 'mentor') {
    NotificationService::sendOnceSince(
        $con,
        (int)$row['mentee_id'],
        'session_ended',
        $isOver ? 'Session Ended' : 'Mentor Left the Session',
        $isOver
            ? "Your session with {$row['mentor_fname']} {$row['mentor_lname']} has ended."
            : "{$row['mentor_fname']} {$row['mentor_lname']} left before the session was due to finish. "
              . "You can rejoin until it ends.",
        $isOver
            ? url('mentee-submit-feedback') . '?session_id=' . $session_id . '&mentor_id=' . $row['mentor_id']
            : url('video-join') . '?session_id=' . $session_id,
        $since
    );
} else {
    NotificationService::sendOnceSince(
        $con,
        (int)$row['mentor_id'],
        'session_ended',
        $isOver ? 'Session Ended' : 'Mentee Left the Session',
        $isOver
            ? "{$row['mentee_fname']} {$row['mentee_lname']} left the session."
            : "{$row['mentee_fname']} {$row['mentee_lname']} left before the session was due to finish.",
        // The lobby, not the Upcoming list: it names the session this is
        // about, which is what makes one notice per session tell them apart.
        $isOver ? url('mentor-completed') : url('video-join') . '?session_id=' . $session_id,
        $since
    );
}

// 'rejoinable' is what the call page uses to decide whether to offer going
// back in rather than sending them away.
echo json_encode([
    'ok'         => true,
    'status'     => $outcome,
    'ended'      => $isOver,
    'rejoinable' => !$isOver,
]);
