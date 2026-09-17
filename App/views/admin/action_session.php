<?php

/**
 * action_session.php — what an admin can do to a session.
 *
 * Deliberately narrow. An admin here is settling something the two people in
 * the session could not: closing a session nobody marked complete, recording
 * one that never happened, or calling one off. Each action tells both
 * participants, because a session changing under them without a word is
 * worse than it not changing.
 *
 * There is no "move". A session is tied to its mentor's availability slot by
 * date and start time, so changing only the session's time left the slot
 * behind: the session lost its length and type, the old time could be booked
 * again, and a group student ended up in a different call room from the
 * rest. An admin cancels with a reason instead, and the mentee books again.
 *
 * SECURITY: admin-only, POST-only, CSRF-checked, prepared statements.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/GoogleCalendarService.php';
require_once __DIR__ . '/../../services/MentorScoreService.php';

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

if (!$id || !in_array($action, ['complete', 'cancel', 'missed'], true)) {
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

$ref  = 'the ' . ($s['subject'] ?: 'mentoring') . ' session';
$when = date('M j, g:i A', strtotime($s['session_date']));

// How the activity log names this session.
$logRef = 'session #' . $id . ' (' . ($s['subject'] ?: 'mentoring') . ', '
        . trim($s['mentor_name']) . ' with ' . trim($s['mentee_name']) . ')';

/** Tell both people the same thing. */
$tell = function (string $title, string $message) use ($con, $s) {
    foreach ([(int)$s['mentor_id'] => url('mentor-requests'), (int)$s['mentee_id'] => url('mentee-sessions')] as $uid => $link) {
        NotificationService::send($con, $uid, 'session_cancelled', $title, $message, $link);
    }
};

/**
 * Completing a session or recording it as missed changes the mentor's score,
 * so it is recalculated straight away, as the missed-session job and feedback
 * do. The session is already closed by then; a failure here is logged rather
 * than shown, and the 30-minute maintenance run catches it up.
 */
$rescore = function () use ($con, $s) {
    try {
        MentorScoreService::compute($con, (int)$s['mentor_id']);
    } catch (Throwable $e) {
        error_log('MentorScoreService::compute failed after an admin session action: ' . $e->getMessage());
    }
};

switch ($action) {

    case 'complete':
        // Only something that actually ran can be closed as done.
        if (!in_array($s['status'], ['approved', 'missed'], true)) {
            pc_flash('warning', 'Only an accepted session can be marked complete.');
            break;
        }
        if (strtotime($s['session_date']) > time()) {
            pc_flash('warning', 'That session has not started yet, so it cannot be marked complete.');
            break;
        }

        if (SessionRepository::completeByAdmin($con, $id) > 0) {
            $rescore();
            $tell('Session Completed', 'An admin closed ' . $ref . ' on ' . $when . ' as completed. You can leave feedback for it now.');
            pc_admin_log('closed ' . $logRef . ' as completed');
            pc_flash('success', 'It is closed as completed and both people can now leave feedback.', 'Session completed');
        } else {
            pc_flash('warning', 'Nothing changed — it may already be closed.');
        }
        break;

    case 'missed':
        if (!in_array($s['status'], ['approved'], true)) {
            pc_flash('warning', 'Only an accepted session can be recorded as missed.');
            break;
        }
        $by = in_array($_POST['missed_by'] ?? '', ['mentor', 'mentee', 'both'], true) ? $_POST['missed_by'] : 'both';

        if (SessionRepository::markMissedByAdmin($con, $id, $by) > 0) {
            $rescore();
            $tell('Session Missed', 'An admin recorded ' . $ref . ' on ' . $when . ' as missed.');
            pc_admin_log('recorded ' . $logRef . ' as missed by ' . $by);
            pc_flash('success', 'It is recorded as missed and both people have been told.', 'Marked missed');
        } else {
            pc_flash('warning', 'Nothing changed.');
        }
        break;

    case 'cancel':
        // Only an open session — pending or accepted — can be called off. A
        // missed session has already been settled, and cancelling it would
        // wipe out the record of who did not turn up.
        if (!in_array($s['status'], ['pending', 'approved'], true)) {
            pc_flash('warning', 'That session is already closed, so there is nothing to cancel.');
            break;
        }
        if ($reason === '') {
            pc_flash('error', 'A reason is required — it is what both people are told.');
            break;
        }

        if (SessionRepository::cancelByAdmin($con, $id, $reason) > 0) {
            // Take it back out of any connected Google Calendar. Never fatal.
            GoogleCalendarService::pushSession($con, $id);
            $tell('Session Cancelled', 'An admin cancelled ' . $ref . ' on ' . $when . '. Reason: ' . $reason);
            // The reason is kept on the session itself (rejection_reason).
            pc_admin_log('cancelled ' . $logRef);
            pc_flash('success', 'Both people have been told, along with your reason.', 'Session cancelled');
        } else {
            pc_flash('warning', 'Nothing changed.');
        }
        break;
}

header('Location: ' . $back);
exit;
