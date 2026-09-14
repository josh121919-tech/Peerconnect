<?php

define('RUNNING_AS_CRON', PHP_SAPI === 'cli');

/*
 * One hour after an approved session ends (PC_MISSED_GRACE_HOURS), if nobody
 * closed it, this decides what happened from session_attendance — who opened
 * the video call:
 *
 *   both joined     closed as completed; the mentee is asked for feedback
 *   only the mentor missed by the mentee; the mentee is told they missed it,
 *                   the mentor that it does not count against them
 *   only the mentee missed by the mentor, the same the other way round
 *   nobody          missed by both; both are told
 *
 * Reached three ways: scripts/maintenance.php includes it every 30 minutes from
 * Task Scheduler, it can be run on its own from the command line, and the
 * button on Platform analytics posts to it.
 *
 * From the command line, --dry-run lists what would be marked and writes
 * nothing:   php App/views/cron/detect_missed_sessions.php --dry-run
 *
 * The web path needs the same guards every other admin
 * action has — this one marks sessions missed, emails both people involved and
 * rewrites mentor scores, so a GET from a stray link must not set it off.
 */
if (!RUNNING_AS_CRON) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Forbidden');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit('Method Not Allowed');
    }
    $expected = $_SESSION['csrf_token'] ?? '';
    $given    = $_POST['csrf_token'] ?? '';
    if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        exit('CSRF token mismatch.');
    }
}

// require_once: scripts/maintenance.php has already loaded it.
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/MentorScoreService.php';

date_default_timezone_set('Asia/Manila');

$now = date('Y-m-d H:i:s');

$dryRun = RUNNING_AS_CRON && in_array('--dry-run', $argv ?? [], true);

// Approved sessions that ended more than PC_MISSED_GRACE_HOURS ago and were
// never closed, with whether each person opened the call.
//
// The mentor's join is matched on the slot, not the request: a group session
// is one call with one request per mentee, and the mentor opens it from
// whichever of those requests they clicked.
// The availability join has to match the exact slot (subject + date + start
// time), the way every other duration lookup in the app does. Matching on the
// date alone returned one row per slot the mentor offered that day, so a
// session was processed — and both parties notified — once per slot.
$stmt = $con->prepare("
    SELECT
        sr.request_id, sr.mentor_id, sr.mentee_id, sr.session_date,
        a.duration,
        DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE) AS session_end,
        CONCAT(um.firstname,' ',um.lastname) AS mentor_name,
        CONCAT(ue.firstname,' ',ue.lastname) AS mentee_name,
        sr.subject,
        EXISTS (
            SELECT 1 FROM session_attendance att
            JOIN session_requests s2 ON s2.request_id = att.session_id
            WHERE att.user_id = sr.mentor_id
              AND s2.mentor_id = sr.mentor_id
              AND s2.subject = sr.subject
              AND s2.session_date = sr.session_date
        ) AS mentor_joined,
        EXISTS (
            SELECT 1 FROM session_attendance att
            WHERE att.session_id = sr.request_id AND att.user_id = sr.mentee_id
        ) AS mentee_joined
    FROM session_requests sr
    LEFT JOIN availability a
        ON a.mentor_id = sr.mentor_id
       AND a.subject   = sr.subject
       AND a.date      = DATE(sr.session_date)
       AND a.start_time = TIME(sr.session_date)
    JOIN users um ON um.user_id = sr.mentor_id
    JOIN users ue ON ue.user_id = sr.mentee_id
    WHERE sr.status = 'approved'
      AND DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE) < DATE_SUB(?, INTERVAL ? HOUR)
      AND NOT EXISTS (
          SELECT 1 FROM missed_session_logs ml WHERE ml.session_id = sr.request_id
      )
");
$graceHours = PC_MISSED_GRACE_HOURS;
$stmt->bind_param("si", $now, $graceHours);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Guarded: this file is included by scripts/maintenance.php as well as run
// directly, and a second include must not redeclare them.
if (!function_exists('msd_outcome')) {
    /** What attendance says happened: 'completed', or who missed it. */
    function msd_outcome(array $row): string
    {
        $mentorIn = (int)$row['mentor_joined'] === 1;
        $menteeIn = (int)$row['mentee_joined'] === 1;
        if ($mentorIn && $menteeIn) return 'completed';
        if ($mentorIn)              return 'mentee';
        if ($menteeIn)              return 'mentor';
        return 'both';
    }

    function msd_describe(int $sid, string $outcome): string
    {
        return '#' . $sid . ($outcome === 'completed' ? ' completed (both joined)' : ' missed by ' . $outcome);
    }
}

if ($dryRun) {
    $plan = array_map(fn($r) => msd_describe((int)$r['request_id'], msd_outcome($r)), $rows);
    echo "[" . date('Y-m-d H:i:s') . "] Dry run, nothing written. " . count($rows) . " session(s) to close"
        . ($plan ? ': ' . implode(', ', $plan) : '') . ".\n";
    return;
}

$processed = 0;   // recorded as missed
$completed = 0;   // closed as completed because both joined
$summary   = [];

foreach ($rows as $row) {
    $sid      = (int)$row['request_id'];
    $mid      = (int)$row['mentor_id'];
    $eid      = (int)$row['mentee_id'];

    $outcome = msd_outcome($row);
    $subject = $row['subject'] !== '' && $row['subject'] !== null ? $row['subject'] : 'mentoring';
    $when    = date('M j, g:i A', strtotime($row['session_date']));

    if ($outcome === 'completed') {
        // Both were in the call, so the session happened; nobody pressed End
        // or left feedback afterwards. "AND status" guards against feedback
        // arriving between the SELECT above and this write.
        $upd = $con->prepare("UPDATE session_requests SET status='completed', completed_at=?, missed_by='none' WHERE request_id=? AND status='approved'");
        $upd->bind_param("si", $now, $sid);
        $upd->execute();
        $changed = $upd->affected_rows;
        $upd->close();
        if ($changed < 1) {
            continue;
        }
        NotificationService::sessionAutoCompleted($con, $eid, $row['mentor_name'], $subject, $when,
            url('mentee-submit-feedback') . '?session_id=' . $sid . '&mentor_id=' . $mid);
        MentorScoreService::compute($con, $mid);
        $completed++;
        $summary[] = msd_describe($sid, $outcome);
        continue;
    }

    $upd = $con->prepare("UPDATE session_requests SET status='missed', missed_by=?, completed_at=? WHERE request_id=? AND status='approved'");
    $upd->bind_param("ssi", $outcome, $now, $sid);
    $upd->execute();
    $changed = $upd->affected_rows;
    $upd->close();
    if ($changed < 1) {
        continue;
    }

    $log = $con->prepare("INSERT IGNORE INTO missed_session_logs (session_id, missed_by, detected_at) VALUES (?, ?, NOW())");
    $log->bind_param("is", $sid, $outcome);
    $log->execute();
    $log->close();

    // Whoever did not join hears that they missed it. When only one of them
    // did not, the other hears that it is not counted against them — which is
    // also how MentorScoreService counts it (missed_by 'mentor' or 'both').
    $mentorLink = url('mentor-completed');
    $menteeLink = url('mentee-sessions');
    if ($outcome === 'both') {
        NotificationService::noShow($con, $mid, $row['mentee_name'], $subject, $when, $mentorLink);
        NotificationService::noShow($con, $eid, $row['mentor_name'], $subject, $when, $menteeLink);
    } elseif ($outcome === 'mentee') {
        NotificationService::noShow($con, $eid, $row['mentor_name'], $subject, $when, $menteeLink);
        NotificationService::otherNoShow($con, $mid, $row['mentee_name'], $subject, $when, $mentorLink);
    } else {
        NotificationService::noShow($con, $mid, $row['mentee_name'], $subject, $when, $mentorLink);
        NotificationService::otherNoShow($con, $eid, $row['mentor_name'], $subject, $when, $menteeLink);
    }

    MentorScoreService::compute($con, $mid);

    $processed++;
    $summary[] = msd_describe($sid, $outcome);
}

if (RUNNING_AS_CRON) {
    // Session ids only: this line goes into a log file, and names do not need to.
    echo "[" . date('Y-m-d H:i:s') . "] Closed " . ($processed + $completed) . " session(s)"
        . ($summary ? ': ' . implode(', ', $summary) : '') . ".\n";
    // Run on its own, stop here. Included by scripts/maintenance.php, hand
    // control back so the score refresh after it still runs.
    if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
        exit;
    }
    return;
}

/*
 * Back to the page the button is on, with a toast. This used to print raw JSON,
 * which left whoever pressed it looking at {"processed":0} on a blank page with
 * no way back.
 */
$graceLabel = PC_MISSED_GRACE_HOURS . ' hour' . (PC_MISSED_GRACE_HOURS === 1 ? '' : 's');
$parts = [];
if ($processed > 0) {
    $parts[] = 'recorded ' . $processed . ' missed session' . ($processed === 1 ? '' : 's');
}
if ($completed > 0) {
    $parts[] = 'closed ' . $completed . ' as completed because both people joined';
}
// Only the button is an admin action; the scheduled run above is not logged here.
pc_admin_log('ran the missed-session check: ' . ($summary ? implode(', ', $summary) : 'nothing to close'));
pc_flash(
    $parts ? 'success' : 'info',
    $parts
        ? ucfirst(implode(' and ', $parts)) . '. Everyone involved has been notified.'
        : 'Nothing needed closing — no approved session is more than ' . $graceLabel . ' past its end.',
    'Missed session check'
);
header('Location: ' . url('admin-analytics'));
exit;
