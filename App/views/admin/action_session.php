<?php

/**
 * action_session.php — what an admin can do to a session.
 *
 * Deliberately narrow. An admin here is settling something the two people in
 * the session could not: closing a session nobody marked complete, calling
 * one off, or moving it. Each action tells both participants, because a
 * session changing under them without a word is worse than it not changing.
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

$id     = (int)($_POST['request_id'] ?? 0);
$action = $_POST['action'] ?? '';
$reason = trim($_POST['reason'] ?? '');
$back   = $_POST['back'] ?? '';

// Only back to a page inside this install, never wherever a form says.
if ($back === '' || strpos($back, BASE_URL . '/') !== 0) {
    $back = url('admin-sessions');
}

if (!$id || !in_array($action, ['complete', 'cancel', 'reschedule', 'missed'], true)) {
    pc_flash('error', 'That action could not be carried out.');
    header('Location: ' . $back);
    exit;
}

$st = $con->prepare("
    SELECT sr.request_id, sr.subject, sr.session_date, sr.status, sr.mentor_id, sr.mentee_id,
           CONCAT_WS(' ', mo.firstname, mo.lastname) AS mentor_name,
           CONCAT_WS(' ', me.firstname, me.lastname) AS mentee_name
    FROM session_requests sr
    JOIN users mo ON mo.user_id = sr.mentor_id
    JOIN users me ON me.user_id = sr.mentee_id
    WHERE sr.request_id = ?
    LIMIT 1
");
$st->bind_param('i', $id);
$st->execute();
$s = $st->get_result()->fetch_assoc();
$st->close();

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
        $up = $con->prepare("UPDATE session_requests SET status = 'completed', completed_at = NOW(), missed_by = 'none' WHERE request_id = ?");
        $up->bind_param('i', $id);
        $up->execute();
        $ok = $up->affected_rows > 0;
        $up->close();

        if ($ok) {
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
        $up = $con->prepare("UPDATE session_requests SET status = 'missed', missed_by = ? WHERE request_id = ?");
        $up->bind_param('si', $by, $id);
        $up->execute();
        $ok = $up->affected_rows > 0;
        $up->close();

        if ($ok) {
            $tell('Session Missed', 'An admin recorded ' . $ref . ' on ' . $when . ' as missed.');
            pc_admin_log('recorded ' . $logRef . ' as missed by ' . $by);
            pc_flash('success', 'It is recorded as missed and both people have been told.', 'Marked missed');
        } else {
            pc_flash('warning', 'Nothing changed.');
        }
        break;

    case 'cancel':
        if (in_array($s['status'], ['cancelled', 'completed', 'rejected'], true)) {
            pc_flash('warning', 'That session is already closed, so there is nothing to cancel.');
            break;
        }
        if ($reason === '') {
            pc_flash('error', 'A reason is required — it is what both people are told.');
            break;
        }
        $up = $con->prepare("UPDATE session_requests SET status = 'cancelled', rejection_reason = ? WHERE request_id = ?");
        $up->bind_param('si', $reason, $id);
        $up->execute();
        $ok = $up->affected_rows > 0;
        $up->close();

        if ($ok) {
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

    case 'reschedule':
        if (in_array($s['status'], ['cancelled', 'completed', 'rejected'], true)) {
            pc_flash('warning', 'A closed session cannot be moved. Ask them to book a new one.');
            break;
        }
        $date = trim($_POST['date'] ?? '');
        $time = trim($_POST['time'] ?? '');
        $newTs = ($date !== '' && $time !== '') ? strtotime($date . ' ' . $time) : false;

        if (!$newTs) {
            pc_flash('error', 'Give the new date and time before moving a session.');
            break;
        }
        if ($newTs < time()) {
            pc_flash('error', 'A session cannot be moved into the past.');
            break;
        }

        $newDt = date('Y-m-d H:i:s', $newTs);

        // Do not move it on top of something else this mentor is already
        // committed to — that is a clash the mentor would discover on the day.
        $clash = $con->prepare("
            SELECT COUNT(*) c FROM session_requests
            WHERE mentor_id = ? AND request_id <> ? AND session_date = ?
              AND status IN ('pending','approved')
        ");
        $clash->bind_param('iis', $s['mentor_id'], $id, $newDt);
        $clash->execute();
        $busy = (int)($clash->get_result()->fetch_assoc()['c'] ?? 0);
        $clash->close();

        if ($busy > 0) {
            pc_flash('error', 'That mentor already has a session at that time.');
            break;
        }

        $up = $con->prepare("UPDATE session_requests SET session_date = ? WHERE request_id = ?");
        $up->bind_param('si', $newDt, $id);
        $up->execute();
        $ok = $up->affected_rows > 0;
        $up->close();

        if ($ok) {
            GoogleCalendarService::pushSession($con, $id);
            $newWhen = date('M j, g:i A', $newTs);
            $note = $reason !== '' ? ' Reason: ' . $reason : '';
            $tell('Session Moved', 'An admin moved ' . $ref . ' from ' . $when . ' to ' . $newWhen . '.' . $note);
            pc_admin_log('moved ' . $logRef . ' from ' . $when . ' to ' . $newWhen);
            pc_flash('success', 'Moved to ' . $newWhen . ' — both people have been told.', 'Session rescheduled');
        } else {
            pc_flash('warning', 'That is already when the session is.');
        }
        break;
}

header('Location: ' . $back);
exit;
