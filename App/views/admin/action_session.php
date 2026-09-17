<?php

/**
 * action_session.php — what an admin can do to a session: call it off.
 *
 * Deliberately narrow. The admin settles what the two people in a session
 * could not by cancelling it with a reason, and both of them are told,
 * because a session changing under them without a word is worse than it not
 * changing.
 *
 * An admin cannot close a session as completed or missed. That is decided
 * from what actually happened, never by hand: the mentor ending the call or
 * the mentee leaving feedback completes it, and otherwise the missed-session
 * job (App/views/cron/detect_missed_sessions.php, every 30 minutes) closes it
 * an hour after it ends from who joined the call.
 *
 * There is no "move" either. A session is tied to its mentor's availability
 * slot by date and start time, so changing only the session's time left the
 * slot behind: the session lost its length and type, the old time could be
 * booked again, and a group student ended up in a different call room from
 * the rest. An admin cancels instead, and the mentee books again.
 *
 * SECURITY: admin-only, POST-only, CSRF-checked, prepared statements.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/GoogleCalendarService.php';

require_admin();
require_post();

if (!verify_csrf()) {
    http_response_code(403);
    exit('CSRF token mismatch.');
}

date_default_timezone_set('Asia/Manila');

// A field sent as a list is treated as missing.
$field = fn(string $name): string => is_string($_POST[$name] ?? null) ? $_POST[$name] : '';

$id     = (int)$field('request_id');
$action = $field('action');
$reason = trim($field('reason'));
$back   = $field('back');

// Only back to a page inside this install, never wherever a form says. Also
// refused: an address a redirect header cannot carry (control characters),
// and "//host" or a backslash, which a browser reads as another site — the
// prefix check alone would let those through for an install at a domain's
// root, where BASE_URL is empty.
if ($back === '' || strpos($back, BASE_URL . '/') !== 0
    || strpos($back, '//') === 0 || strpos($back, '\\') !== false
    || preg_match('/[\x00-\x1F\x7F]/', $back)) {
    $back = url('admin-sessions');
}

if (!$id || $action !== 'cancel') {
    pc_flash('error', 'That action could not be carried out.');
    header('Location: ' . $back);
    exit;
}

$s = SessionRepository::findWithNames($con, $id);

if (!$s) {
    pc_flash('error', 'That session no longer exists.');
    header('Location: ' . $back);
    exit;
}

// Only an open session — pending or accepted — can be called off. A missed
// session has already been settled, and cancelling it would wipe out the
// record of who did not turn up.
if (!in_array($s['status'], ['pending', 'approved'], true)) {
    pc_flash('warning', 'That session is already closed, so there is nothing to cancel.');
    header('Location: ' . $back);
    exit;
}
if ($reason === '') {
    pc_flash('error', 'A reason is required — it is what both people are told.');
    header('Location: ' . $back);
    exit;
}

$ref  = 'the ' . ($s['subject'] ?: 'mentoring') . ' session';
$when = date('M j, g:i A', strtotime($s['session_date']));

if (SessionRepository::cancelByAdmin($con, $id, $reason) > 0) {
    // Take it back out of any connected Google Calendar. Never fatal.
    GoogleCalendarService::pushSession($con, $id);

    $message = 'An admin cancelled ' . $ref . ' on ' . $when . '. Reason: ' . $reason;
    foreach ([(int)$s['mentor_id'] => url('mentor-requests'), (int)$s['mentee_id'] => url('mentee-sessions')] as $uid => $link) {
        NotificationService::send($con, $uid, 'session_cancelled', 'Session Cancelled', $message, $link);
    }

    // The reason is kept on the session itself (rejection_reason).
    pc_admin_log('cancelled session #' . $id . ' (' . ($s['subject'] ?: 'mentoring') . ', '
        . trim($s['mentor_name']) . ' with ' . trim($s['mentee_name']) . ')');
    pc_flash('success', 'Both people have been told, along with your reason.', 'Session cancelled');
} else {
    pc_flash('warning', 'Nothing changed.');
}

header('Location: ' . $back);
exit;
