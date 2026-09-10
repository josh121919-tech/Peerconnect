<?php

define('RUNNING_AS_CRON', PHP_SAPI === 'cli');

/*
 * Reached two ways: from the command line on a schedule, and from the button on
 * Platform analytics. The web path needs the same guards every other admin
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

include __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/MentorScoreService.php';

date_default_timezone_set('Asia/Manila');

$now = date('Y-m-d H:i:s');

// Find approved sessions whose end time has passed and aren't yet completed/missed.
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
        CONCAT(ue.firstname,' ',ue.lastname) AS mentee_name
    FROM session_requests sr
    LEFT JOIN availability a
        ON a.mentor_id = sr.mentor_id
       AND a.subject   = sr.subject
       AND a.date      = DATE(sr.session_date)
       AND a.start_time = TIME(sr.session_date)
    JOIN users um ON um.user_id = sr.mentor_id
    JOIN users ue ON ue.user_id = sr.mentee_id
    WHERE sr.status = 'approved'
      AND DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE) < ?
      AND NOT EXISTS (
          SELECT 1 FROM missed_session_logs ml WHERE ml.session_id = sr.request_id
      )
");
$stmt->bind_param("s", $now);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$processed = 0;

foreach ($rows as $row) {
    $sid      = (int)$row['request_id'];
    $mid      = (int)$row['mentor_id'];
    $eid      = (int)$row['mentee_id'];

    // Mark the session as missed (could detect who missed based on join log — default 'both' for now)
    $upd = $con->prepare("UPDATE session_requests SET status='missed', missed_by='both', completed_at=? WHERE request_id=?");
    $upd->bind_param("si", $now, $sid);
    $upd->execute();
    $upd->close();

    // Log it
    $log = $con->prepare("INSERT IGNORE INTO missed_session_logs (session_id, missed_by, detected_at) VALUES (?, 'both', NOW())");
    $log->bind_param("i", $sid);
    $log->execute();
    $log->close();

    // Notify both parties
    NotificationService::missedSession($con, $mid, $row['mentee_name'], url('mentor-completed'));
    NotificationService::missedSession($con, $eid, $row['mentor_name'],  url('mentee-sessions'));

    // Recompute mentor score (reliability impacts score)
    MentorScoreService::compute($con, $mid);

    $processed++;
}

if (RUNNING_AS_CRON) {
    echo "[" . date('Y-m-d H:i:s') . "] Processed {$processed} missed session(s).\n";
    exit;
}

/*
 * Back to the page the button is on, with a toast. This used to print raw JSON,
 * which left whoever pressed it looking at {"processed":0} on a blank page with
 * no way back.
 */
pc_flash(
    $processed > 0 ? 'success' : 'info',
    $processed > 0
        ? 'Marked ' . $processed . ' session' . ($processed === 1 ? '' : 's')
          . ' as missed. Both people in each one have been notified.'
        : 'Nothing needed marking — no approved session has passed its end time.',
    'Missed session check'
);
header('Location: ' . url('admin-analytics'));
exit;
