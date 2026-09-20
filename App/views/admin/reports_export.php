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
pc_admin_log('exported the overall summary report (' . $name . ')');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
// Excel reads a CSV as the system codepage unless the file says otherwise;
// without this, a name with an accent in it arrives mangled.
fwrite($out, "\xEF\xBB\xBF");

$section = function (string $title) use ($out) {
    CsvExport::row($out, []);
    CsvExport::row($out, [$title]);
};

/* ── What this file is ─────────────────────────────────────────────────── */
CsvExport::row($out, ['PeerConnect — overall summary']);
CsvExport::row($out, ['Range', $R['key'] === 'all' ? 'All time' : $R['label']]);
CsvExport::row($out, ['From', $from]);
CsvExport::row($out, ['To', $to]);
CsvExport::row($out, ['Days in range', $R['days']]);
CsvExport::row($out, ['Compared against', $prev ? $R['prev'][0] . ' to ' . $R['prev'][1] : 'Nothing — this range has no period before it']);
CsvExport::row($out, ['Generated', date('Y-m-d H:i:s') . ' (Asia/Manila)']);

/* ── Headline figures ──────────────────────────────────────────────────── */
$section('Headline figures');
CsvExport::row($out, ['Measure', 'This range', 'Previous range', 'Change %']);

$rows = [
    'New members'          => 'joined',
    'Active members'       => 'active',
    'Sessions'             => 'sessions',
    'Sessions completed'   => 'completed',
    'Assessments submitted' => 'assessments',
    'Messages sent'        => 'messages',
    'Feedback written'     => 'feedback',
    'Resources uploaded'   => 'resources',
    'Sign-ins by members'  => 'signins',
];
foreach ($rows as $label => $k) {
    $d = rp_delta($now[$k], $prev ? $prev[$k] : null);
    $change = $d === null ? 'n/a' : ($d[0] === 'new' ? 'no previous data' : ($d[0] === 'up' ? '+' : '') . $d[1]);
    CsvExport::row($out, [$label, $now[$k], $prev ? $prev[$k] : '', $change]);
}

/* ── Activity by area ──────────────────────────────────────────────────── */
$section('Records created, by area');
CsvExport::row($out, ['Area', 'Records', 'Share %']);
$areas = rp_areas($now);
$areaTotal = array_sum(array_column($areas, 1));
foreach ($areas as [$label, $n]) {
    CsvExport::row($out, [$label, $n, $areaTotal > 0 ? round($n / $areaTotal * 100) : 0]);
}

/* ── Member mix ────────────────────────────────────────────────────────── */
$section('Accounts by role (all time, not filtered by range)');
CsvExport::row($out, ['Role', 'Accounts']);
foreach (SummaryRepository::roleMix($con) as $role => $n) CsvExport::row($out, [$role, $n]);

/* ── Growth, bucket by bucket ──────────────────────────────────────────── */
$B = rp_buckets($from, $to);
$section('Activity per ' . $B['unit'] . ' (mentees and mentors only — admin accounts are not members)');
CsvExport::row($out, [ucfirst($B['unit']) . ' starting', 'Mentees joined', 'Mentors joined', 'Sessions', 'Sessions completed']);

$reg = [];
foreach (array_keys($B['keys']) as $k) $reg[$k] = ['mentee' => 0, 'mentor' => 0, 'sess' => 0, 'done' => 0];

foreach (SummaryRepository::joinsPerBucket($con, $B['unit'], $from, $to) as $row) {
    if (isset($reg[$row['k']])) $reg[$row['k']][$row['role']] = (int)$row['n'];
}
foreach (SummaryRepository::sessionsPerBucket($con, $B['unit'], $from, $to) as $row) {
    if (isset($reg[$row['k']])) {
        $reg[$row['k']]['sess'] = (int)$row['n'];
        $reg[$row['k']]['done'] = (int)$row['done'];
    }
}
foreach ($reg as $k => $v) {
    CsvExport::row($out, [$k, $v['mentee'], $v['mentor'], $v['sess'], $v['done']]);
}

/* ── Subjects ──────────────────────────────────────────────────────────── */
$section('Subjects booked in this range');
CsvExport::row($out, ['Subject', 'Sessions']);
$anySubject = false;
foreach (SummaryRepository::subjects($con, $from, $to) as $row) { CsvExport::row($out, [$row['subject'], $row['n']]); $anySubject = true; }
if (!$anySubject) CsvExport::row($out, ['No sessions in this range', 0]);

/* ── Engagement ────────────────────────────────────────────────────────── */
$people    = SummaryRepository::signInPeople($con, $from, $to);
$ratingRow = SummaryRepository::ratings($con, $from, $to);
$concluded = SummaryRepository::concluded($con, $from, $to);

$section('Engagement');
CsvExport::row($out, ['Measure', 'Value']);
CsvExport::row($out, ['Sign-ins by members', $now['signins']]);
CsvExport::row($out, ['Members who signed in', $people['people']]);
CsvExport::row($out, ['Came back on another day', $people['returned']]);
CsvExport::row($out, ['Sessions that concluded', $concluded]);
CsvExport::row($out, ['Completion rate %', $concluded > 0 ? round($now['completed'] / $concluded * 100) : 'n/a']);
CsvExport::row($out, ['Reviews written', (int)$ratingRow['n']]);
CsvExport::row($out, ['Average rating out of 5', (int)$ratingRow['n'] > 0 ? round((float)$ratingRow['a'], 2) : 'n/a']);

/* ── Email delivery ────────────────────────────────────────────────────── */
$section('Notification email in this range');
CsvExport::row($out, ['Status', 'Notifications']);
$anyMail = false;
foreach (SummaryRepository::emailStatuses($con, $from, $to) as $status => $n) { CsvExport::row($out, [$status, $n]); $anyMail = true; }
if (!$anyMail) CsvExport::row($out, ['No notifications raised', 0]);

/* ── The gap, stated in the file too ───────────────────────────────────── */
$section('Not measured by this platform');
CsvExport::row($out, ['Average time on site', 'No page views are recorded']);
CsvExport::row($out, ['Pages per visit', 'No page views are recorded']);
CsvExport::row($out, ['Uptime %', 'No monitor runs against this install']);
CsvExport::row($out, ['Average response time', 'No monitor runs against this install']);

fclose($out);
exit;
