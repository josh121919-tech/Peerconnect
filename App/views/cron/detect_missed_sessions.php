<?php

define('RUNNING_AS_CRON', PHP_SAPI === 'cli');

/*
 * A short while after an approved session ends (PC_MISSED_GRACE_MINUTES), if nobody
 * closed it, this decides what happened from session_attendance — who opened
 * the video call:
 *
 *   both joined     closed as completed; the mentee is asked for feedback
 *   only the mentor missed by the mentee; the mentee is told they missed it,
 *                   the mentor that it does not count against them
 *   only the mentee missed by the mentor, the same the other way round
 *   nobody          missed by both; both are told
 *
 * It also removes requests the mentor never answered once their start time
 * has passed: they can no longer take place as booked, and a mentor can no
 * longer accept them. The mentee is told so they can book another time, and
 * the mentor that the request lapsed.
 *
 * This is the only thing that closes a session nobody closed: admins cannot
 * mark sessions completed or missed by hand. The mentor ending the call, and
 * the mentee leaving feedback when both of them joined, complete a session
 * before it gets here.
 *
 * Reached three ways: scripts/maintenance.php includes it every 30 minutes from
 * Task Scheduler, it can be run on its own from the command line, and two
 * admin buttons post to it — "Detect missed sessions" on Platform analytics,
 * and "Run the check now" on All Sessions, which appears when sessions have
 * gone unclosed for long enough that the scheduled run has evidently stopped.
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
    if (!verify_csrf()) {
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

// Approved sessions that ended more than PC_MISSED_GRACE_MINUTES ago and were
// never closed, with whether each person opened the call. How the mentor's
// join and the session's length are worked out is described on the query.
$rows = SessionRepository::dueForMissedCheck($con, $now, PC_MISSED_GRACE_MINUTES);

// Requests nobody answered whose start time has come.
$unanswered = SessionRepository::unansweredRequests($con, $now);

// Guarded: this file is included by scripts/maintenance.php as well as run
// directly, and a second include must not redeclare them.
if (!function_exists('msd_outcome')) {
    /**
     * What happened: 'completed', 'unfinished', or who missed it. The rule
     * itself lives on SessionRepository, because the call closes sessions too
     * and the two must not disagree about the same session.
     *
     * The *_stayed columns on the row are the old still-in-at-the-end rule;
     * outcomeForEnded() uses them only for sessions that ran before presence
     * was being recorded, and reads recorded presence for everything since.
     */
    function msd_outcome(mysqli $con, array $row): string
    {
        return SessionRepository::outcomeForEnded(
            $con,
            (int)$row['request_id'],
            (int)$row['mentor_id'],
            (int)$row['mentee_id'],
            (int)($row['duration'] ?? 60),
            (int)$row['mentor_joined'] === 1,
            (int)$row['mentee_joined'] === 1,
            (int)($row['mentor_stayed'] ?? 0) === 1,
            (int)($row['mentee_stayed'] ?? 0) === 1
        );
    }

    function msd_describe(int $sid, string $outcome): string
    {
        if ($outcome === 'completed')  return '#' . $sid . ' completed (both stayed to the end)';
        if ($outcome === 'unfinished') return '#' . $sid . ' unfinished (left before the end)';
        return '#' . $sid . ' missed by ' . $outcome;
    }
}

if ($dryRun) {
    // Same reckoning as the loop below, including the ones it will leave
    // alone: a dry run that lists a session the real run then ignores is how
    // this went unnoticed in the first place.
    $plan = [];
    foreach ($rows as $r) {
        $o = msd_outcome($con, $r);
        $plan[] = $o === $r['status']
            ? '#' . (int)$r['request_id'] . ' already ' . $o . ', nothing to do'
            : msd_describe((int)$r['request_id'], $o);
    }
    $lapsed = array_map(fn($r) => '#' . (int)$r['request_id'], $unanswered);
    echo "[" . date('Y-m-d H:i:s') . "] Dry run, nothing written. " . count($rows) . " session(s) considered"
        . ($plan ? ': ' . implode(', ', $plan) : '') . "."
        . ($lapsed ? ' ' . count($lapsed) . ' unanswered request(s) to remove: ' . implode(', ', $lapsed) . '.' : '')
        . "\n";
    return;
}

$processed  = 0;   // recorded as missed
$completed  = 0;   // closed as completed because both stayed to the end
$unfinished = 0;   // closed as unfinished because somebody left early
$settled    = 0;   // already in the right state; nothing to do and nobody to tell
$summary    = [];

foreach ($rows as $row) {
    $sid      = (int)$row['request_id'];
    $mid      = (int)$row['mentor_id'];
    $eid      = (int)$row['mentee_id'];

    $outcome = msd_outcome($con, $row);
    $subject = $row['subject'] !== '' && $row['subject'] !== null ? $row['subject'] : 'mentoring';
    $when    = date('M j, g:i A', strtotime($row['session_date']));

    /*
     * Already where it should be. A session someone walked out of is marked
     * unfinished by the call itself, which tells both people at the time; if
     * the recomputed answer is the same, there is nothing to write and no
     * news to deliver. This used to fall through to the write below, which
     * reported 0 rows changed — an UPDATE that sets the values already there
     * — and was read as a failure, so the session was skipped in silence and
     * offered up again on every run.
     */
    if ($outcome === $row['status']) {
        $settled++;
        continue;
    }

    if ($outcome === 'completed') {
        // Both were in the call, so the session happened; nobody pressed End
        // or left feedback afterwards. "AND status" guards against feedback
        // arriving between the SELECT above and this write.
        if (SessionRepository::closeAsCompleted($con, $sid, $now) < 1) {
            continue;
        }
        NotificationService::sessionAutoCompleted($con, $eid, $row['mentor_name'], $subject, $when,
            url('mentee-submit-feedback') . '?session_id=' . $sid . '&mentor_id=' . $mid);
        MentorScoreService::compute($con, $mid);
        $completed++;
        $summary[] = msd_describe($sid, $outcome);
        continue;
    }

    if ($outcome === 'unfinished') {
        // Both were in the call and at least one walked out before the end
        // without coming back while the slot was still open. Nobody missed
        // it, so nothing is logged against either of them and the mentor's
        // score is left alone — missed_session_logs and MentorScoreService
        // are both about not turning up, which is not what happened here.
        if (SessionRepository::closeAsUnfinished($con, $sid) < 1) {
            continue;
        }
        NotificationService::send($con, $eid, 'session_ended', 'Session Left Unfinished',
            "Your $subject session with {$row['mentor_name']} on $when ended before it was due to finish.",
            url('mentee-sessions'));
        NotificationService::send($con, $mid, 'session_ended', 'Session Left Unfinished',
            "Your $subject session with {$row['mentee_name']} on $when ended before it was due to finish.",
            url('mentor-history'));
        $unfinished++;
        $summary[] = msd_describe($sid, $outcome);
        continue;
    }

    if (SessionRepository::closeAsMissed($con, $sid, $outcome, $now) < 1) {
        continue;
    }

    SessionRepository::logMissed($con, $sid, $outcome);

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

$removed = [];   // unanswered requests deleted
foreach ($unanswered as $req) {
    $rid = (int)$req['request_id'];
    // Zero when the mentor answered it between the SELECT above and now.
    if (SessionRepository::deleteUnansweredRequest($con, $rid, $now) < 1) {
        continue;
    }
    $subject = $req['subject'] !== '' && $req['subject'] !== null ? $req['subject'] : 'mentoring';
    $when    = date('M j, g:i A', strtotime($req['session_date']));
    NotificationService::requestExpired($con, (int)$req['mentee_id'], $req['mentor_name'], $subject, $when,
        url('mentee-view-mentor') . '?id=' . (int)$req['mentor_id']);
    NotificationService::requestExpiredForMentor($con, (int)$req['mentor_id'], $req['mentee_name'], $subject, $when,
        url('mentor-requests'));
    $removed[] = '#' . $rid;
}

if (RUNNING_AS_CRON) {
    // Session ids only: this line goes into a log file, and names do not need to.
    echo "[" . date('Y-m-d H:i:s') . "] Closed " . ($processed + $completed + $unfinished) . " session(s)"
        . ($summary ? ': ' . implode(', ', $summary) : '') . "."
        . ($settled ? ' Left ' . $settled . ' already settled by the call itself.' : '')
        . ($removed ? ' Removed ' . count($removed) . ' unanswered request(s): ' . implode(', ', $removed) . '.' : '')
        . "\n";
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
$graceLabel = pc_missed_grace_label();
$parts = [];
if ($processed > 0) {
    $parts[] = 'recorded ' . $processed . ' missed session' . ($processed === 1 ? '' : 's');
}
if ($completed > 0) {
    $parts[] = 'closed ' . $completed . ' as completed because both people stayed to the end';
}
if ($unfinished > 0) {
    $parts[] = 'closed ' . $unfinished . ' as unfinished because somebody left early';
}
if ($settled > 0) {
    $parts[] = 'left ' . $settled . ' already settled by the call itself';
}
if ($removed) {
    $parts[] = 'removed ' . count($removed) . ' unanswered request' . (count($removed) === 1 ? '' : 's') . ' whose time had passed';
}
// Only the button is an admin action; the scheduled run above is not logged here.
$logged = array_merge($summary, array_map(fn($r) => $r . ' removed (not answered in time)', $removed));
pc_admin_log('ran the missed-session check: ' . ($logged ? implode(', ', $logged) : 'nothing to close'));
pc_flash(
    $parts ? 'success' : 'info',
    $parts
        ? ucfirst(implode(', ', array_slice($parts, 0, -1)) . (count($parts) > 1 ? ' and ' : '') . end($parts)) . '. Everyone involved has been notified.'
        : 'Nothing needed closing — no approved session is more than ' . $graceLabel . ' past its end, and no unanswered request has reached its time.',
    'Missed session check'
);

// Back to the page the button was on, if that is a page of this install —
// the same rule the admin session actions apply — or else to Analytics.
$back = is_string($_POST['back'] ?? null) ? $_POST['back'] : '';
if ($back === '' || strpos($back, BASE_URL . '/') !== 0
    || strpos($back, '//') === 0 || strpos($back, '\\') !== false
    || preg_match('/[\x00-\x1F\x7F]/', $back)) {
    $back = url('admin-analytics');
}
header('Location: ' . $back);
exit;
