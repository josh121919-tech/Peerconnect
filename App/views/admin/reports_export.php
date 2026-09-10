<?php

/**
 * reports_export.php — the overall summary, as a file.
 *
 * CSV rather than PDF: nothing in this install can render a PDF (no library
 * is vendored), and an export button that produces nothing is worse than one
 * that is absent. CSV opens in Excel and Sheets, which is where a report like
 * this actually gets used.
 *
 * It reads the same functions the page does, over the same range, so the file
 * and the screen can never disagree.
 *
 * SECURITY: admin-only.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/report_data.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$R    = rp_range($con, $_GET);
$from = $R['from'];
$to   = $R['to'];

$now  = rp_window($con, $from, $to);
$prev = $R['prev'] ? rp_window($con, $R['prev'][0], $R['prev'][1]) : null;

$name = 'peerconnect-summary-' . $from . '-to-' . $to . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
// Excel reads a CSV as the system codepage unless the file says otherwise;
// without this, a name with an accent in it arrives mangled.
fwrite($out, "\xEF\xBB\xBF");

$section = function (string $title) use ($out) {
    fputcsv($out, []);
    fputcsv($out, [$title]);
};

/* ── What this file is ─────────────────────────────────────────────────── */
fputcsv($out, ['PeerConnect — overall summary']);
fputcsv($out, ['Range', $R['key'] === 'all' ? 'All time' : $R['label']]);
fputcsv($out, ['From', $from]);
fputcsv($out, ['To', $to]);
fputcsv($out, ['Days in range', $R['days']]);
fputcsv($out, ['Compared against', $prev ? $R['prev'][0] . ' to ' . $R['prev'][1] : 'Nothing — this range has no period before it']);
fputcsv($out, ['Generated', date('Y-m-d H:i:s') . ' (Asia/Manila)']);

/* ── Headline figures ──────────────────────────────────────────────────── */
$section('Headline figures');
fputcsv($out, ['Measure', 'This range', 'Previous range', 'Change %']);

$rows = [
    'New members'          => 'joined',
    'Active members'       => 'active',
    'Sessions'             => 'sessions',
    'Sessions completed'   => 'completed',
    'Assessments submitted' => 'assessments',
    'Messages sent'        => 'messages',
    'Feedback written'     => 'feedback',
    'Resources uploaded'   => 'resources',
    'Sign-ins'             => 'signins',
];
foreach ($rows as $label => $k) {
    $d = rp_delta($now[$k], $prev ? $prev[$k] : null);
    $change = $d === null ? 'n/a' : ($d[0] === 'new' ? 'no previous data' : ($d[0] === 'up' ? '+' : '') . $d[1]);
    fputcsv($out, [$label, $now[$k], $prev ? $prev[$k] : '', $change]);
}

/* ── Activity by area ──────────────────────────────────────────────────── */
$section('Records created, by area');
fputcsv($out, ['Area', 'Records', 'Share %']);
$areas = rp_areas($con, $from, $to);
$areaTotal = array_sum(array_column($areas, 1));
foreach ($areas as [$label, $n]) {
    fputcsv($out, [$label, $n, $areaTotal > 0 ? round($n / $areaTotal * 100) : 0]);
}

/* ── Member mix ────────────────────────────────────────────────────────── */
$section('Accounts by role (all time, not filtered by range)');
fputcsv($out, ['Role', 'Accounts']);
$rm = $con->query("SELECT IF(role = '' OR role IS NULL, 'unassigned', role) AS r, COUNT(*) n FROM users GROUP BY r ORDER BY n DESC");
while ($row = $rm->fetch_assoc()) fputcsv($out, [$row['r'], $row['n']]);

/* ── Growth, bucket by bucket ──────────────────────────────────────────── */
$B = rp_buckets($from, $to);
$section('Activity per ' . $B['unit'] . ' (mentees and mentors only — admin accounts are not members)');
fputcsv($out, [ucfirst($B['unit']) . ' starting', 'Mentees joined', 'Mentors joined', 'Sessions', 'Sessions completed']);

$reg = [];
foreach (array_keys($B['keys']) as $k) $reg[$k] = ['mentee' => 0, 'mentor' => 0, 'sess' => 0, 'done' => 0];

$g = $con->query("
    SELECT " . rp_bucket_expr($B, 'created_at') . " AS k, role, COUNT(*) n
      FROM users
     WHERE role IN ('mentee','mentor') AND DATE(created_at) BETWEEN '$from' AND '$to'
     GROUP BY k, role");
while ($row = $g->fetch_assoc()) {
    if (isset($reg[$row['k']])) $reg[$row['k']][$row['role']] = (int)$row['n'];
}

$s = $con->query("
    SELECT " . rp_bucket_expr($B, 'session_date') . " AS k,
           COUNT(*) n, SUM(status = 'completed') done
      FROM session_requests
     WHERE DATE(session_date) BETWEEN '$from' AND '$to'
     GROUP BY k");
while ($row = $s->fetch_assoc()) {
    if (isset($reg[$row['k']])) {
        $reg[$row['k']]['sess'] = (int)$row['n'];
        $reg[$row['k']]['done'] = (int)$row['done'];
    }
}
foreach ($reg as $k => $v) {
    fputcsv($out, [$k, $v['mentee'], $v['mentor'], $v['sess'], $v['done']]);
}

/* ── Subjects ──────────────────────────────────────────────────────────── */
$section('Subjects booked in this range');
fputcsv($out, ['Subject', 'Sessions']);
$sub = $con->query("
    SELECT subject, COUNT(*) n FROM session_requests
     WHERE subject IS NOT NULL AND subject <> '' AND DATE(session_date) BETWEEN '$from' AND '$to'
     GROUP BY subject ORDER BY n DESC");
$anySubject = false;
while ($row = $sub->fetch_assoc()) { fputcsv($out, [$row['subject'], $row['n']]); $anySubject = true; }
if (!$anySubject) fputcsv($out, ['No sessions in this range', 0]);

/* ── Engagement ────────────────────────────────────────────────────────── */
$signedIn = rp_int($con, "
    SELECT COUNT(DISTINCT u.user_id) FROM users u JOIN logs l ON l.email = u.email
     WHERE l.activity LIKE '%login%' AND l.log_date >= '$from 00:00:00' AND l.log_date < '$to' + INTERVAL 1 DAY");
$returned = rp_int($con, "
    SELECT COUNT(*) FROM (
        SELECT u.user_id FROM users u JOIN logs l ON l.email = u.email
         WHERE l.activity LIKE '%login%' AND l.log_date >= '$from 00:00:00' AND l.log_date < '$to' + INTERVAL 1 DAY
         GROUP BY u.user_id HAVING COUNT(DISTINCT DATE(l.log_date)) > 1
    ) t");
$ratingRow = $con->query("
    SELECT AVG(rating) a, COUNT(*) n FROM feedback
     WHERE rating > 0 AND DATE(created_at) BETWEEN '$from' AND '$to'")->fetch_assoc();
$concluded = rp_int($con, "
    SELECT COUNT(*) FROM session_requests
     WHERE status IN ('completed','cancelled','rejected','missed')
       AND DATE(session_date) BETWEEN '$from' AND '$to'");

$section('Engagement');
fputcsv($out, ['Measure', 'Value']);
fputcsv($out, ['Sign-ins', $now['signins']]);
fputcsv($out, ['People who signed in', $signedIn]);
fputcsv($out, ['Came back on another day', $returned]);
fputcsv($out, ['Sessions that concluded', $concluded]);
fputcsv($out, ['Completion rate %', $concluded > 0 ? round($now['completed'] / $concluded * 100) : 'n/a']);
fputcsv($out, ['Reviews written', (int)$ratingRow['n']]);
fputcsv($out, ['Average rating out of 5', (int)$ratingRow['n'] > 0 ? round((float)$ratingRow['a'], 2) : 'n/a']);

/* ── Email delivery ────────────────────────────────────────────────────── */
$section('Notification email in this range');
fputcsv($out, ['Status', 'Notifications']);
$mq = $con->query("SELECT email_status, COUNT(*) n FROM notifications WHERE DATE(created_at) BETWEEN '$from' AND '$to' GROUP BY email_status");
$anyMail = false;
while ($row = $mq->fetch_assoc()) { fputcsv($out, [$row['email_status'], $row['n']]); $anyMail = true; }
if (!$anyMail) fputcsv($out, ['No notifications raised', 0]);

/* ── The gap, stated in the file too ───────────────────────────────────── */
$section('Not measured by this platform');
fputcsv($out, ['Average time on site', 'No page views are recorded']);
fputcsv($out, ['Pages per visit', 'No page views are recorded']);
fputcsv($out, ['Uptime %', 'No monitor runs against this install']);
fputcsv($out, ['Average response time', 'No monitor runs against this install']);

fclose($out);
exit;
