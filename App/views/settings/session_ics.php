<?php
/**
 * session_ics.php
 * Downloads sessions as a .ics calendar file, for either the mentor or the
 * mentee on them. Shared under settings/ since it's role-agnostic — same
 * pattern as update_email.php/update_password.php.
 *
 *   ?session_id=123  → just that session
 *   (no session_id)  → every approved session this person has, as one file
 *                      they can import into Google/Outlook/Apple Calendar.
 */
session_start();
require_once __DIR__ . '/../db.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Not authenticated.');
}

$user_id    = (int)$_SESSION['user_id'];
$session_id = (int)($_GET['session_id'] ?? 0);

// The end time uses the slot's length, or 60 minutes without one — the
// fallback the calendar, the missed-session job and the feedback gate use, so
// the exported end agrees with the end those show.
$sessions = SessionRepository::approvedForExport($con, $user_id, $session_id ?: null);

if (!$sessions) {
    http_response_code(404);
    exit($session_id ? 'Session not found.' : 'You have no approved sessions to export yet.');
}

$tz  = new DateTimeZone('Asia/Manila');
$utc = new DateTimeZone('UTC');
$fmt = fn(DateTime $d) => $d->format('Ymd\THis\Z');

// RFC 5545 escaping — keep it simple since our fields are short plain text
$escape = fn(string $s) => str_replace(["\\", ",", ";", "\n"], ["\\\\", "\\,", "\\;", "\\n"], $s);

$ics = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "PRODID:-//PeerConnect//Session Export//EN\r\n"
    . "CALSCALE:GREGORIAN\r\n"
    . "METHOD:PUBLISH\r\n"
    . "X-WR-CALNAME:PeerConnect sessions\r\n";

foreach ($sessions as $session) {
    $isMentor  = ((int)$session['mentor_id'] === $user_id);
    $otherName = $isMentor
        ? trim($session['mentee_fname'] . ' ' . $session['mentee_lname'])
        : trim($session['mentor_fname'] . ' ' . $session['mentor_lname']);
    $otherRole = $isMentor ? 'mentee' : 'mentor';

    $start = new DateTime($session['session_date'], $tz);
    $end   = (clone $start)->modify('+' . (int)$session['duration'] . ' minutes');
    $start->setTimezone($utc);
    $end->setTimezone($utc);

    $ics .= "BEGIN:VEVENT\r\n"
        . "UID:peerconnect-session-" . (int)$session['request_id'] . "@peerconnect\r\n"
        . "DTSTAMP:" . $fmt(new DateTime('now', $utc)) . "\r\n"
        . "DTSTART:" . $fmt($start) . "\r\n"
        . "DTEND:" . $fmt($end) . "\r\n"
        . "SUMMARY:" . $escape('Mentoring session: ' . $session['subject']) . "\r\n"
        . "DESCRIPTION:" . $escape('Session with your ' . $otherRole . ', ' . $otherName . ', via PeerConnect.') . "\r\n"
        . "END:VEVENT\r\n";
}

$ics .= "END:VCALENDAR\r\n";

$filename = $session_id
    ? 'peerconnect-session-' . $session_id . '.ics'
    : 'peerconnect-sessions.ics';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($ics));
echo $ics;
