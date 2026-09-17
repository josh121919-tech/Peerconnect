<?php

/**
 * sessions_export.php — the session list, as a file.
 *
 * CSV rather than PDF: the reference offered a PDF option, but nothing in
 * this install can render one (no PDF library is vendored), and an option
 * that silently produces nothing is worse than one that is absent. CSV opens
 * in Excel and Sheets, which is where a report like this actually gets used.
 *
 * It exports exactly what the filters on screen select, so the file always
 * matches the view it was taken from.
 *
 * SECURITY: admin-only, prepared statements.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/session_data.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$view    = $_GET['tab'] ?? 'all';
$q       = trim((string)($_GET['q'] ?? ''));
$type    = in_array($_GET['type'] ?? '', ['1v1', 'group'], true) ? $_GET['type'] : '';
$subject = trim((string)($_GET['subject'] ?? ''));
$from    = trim((string)($_GET['from'] ?? ''));
$to      = trim((string)($_GET['to'] ?? ''));

$rows = AdminSessionRepository::allMatching($con, [
    'q'       => $q,
    'subject' => $subject,
    'type'    => $type,
    'from'    => ($from !== '' && strtotime($from)) ? date('Y-m-d', strtotime($from)) : '',
    'to'      => ($to !== '' && strtotime($to)) ? date('Y-m-d', strtotime($to)) : '',
], $view);

// Ratings for these sessions specifically, not the people's averages — one
// query for the whole file rather than two for every line of it.
$ids         = array_column($rows, 'request_id');
$menteeRated = FeedbackRepository::ratingsBySession($con, $ids);
$mentorRated = FeedbackRepository::menteeReviewRatingsBySession($con, $ids);

$labels = ad_session_states();
$name = 'peerconnect-sessions-' . date('Y-m-d') . ($view !== 'all' ? '-' . $view : '') . '.csv';

// It holds names and session details, so who took a copy is worth a record.
pc_admin_log('exported ' . count($rows) . ' session' . (count($rows) === 1 ? '' : 's') . ' (' . $name . ')');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
// Excel reads a CSV as the system codepage unless the file says otherwise;
// without this, a name with an accent in it arrives mangled.
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Reference', 'Session ID', 'Subject', 'Topics', 'Type', 'Date', 'Start', 'End',
    'Duration (minutes)', 'State', 'Raw status', 'Mentor', 'Mentor ID', 'Mentee', 'Mentee ID',
    'Missed by', 'Closed at', 'Reason given', 'Mentee rated mentor', 'Mentor rated mentee',
]);

foreach ($rows as $s) {
    $id    = (int)$s['request_id'];
    $mins  = ad_session_minutes($s);
    $start = strtotime($s['session_date']);
    $state = ad_session_state($s);

    fputcsv($out, [
        ad_session_ref($id, $s['session_date']),
        $id,
        $s['subject'],
        $s['topics'],
        ($s['session_type'] ?? '') === 'group' ? 'Group' : (($s['session_type'] ?? '') === '1v1' ? '1-on-1' : ''),
        date('Y-m-d', $start),
        date('H:i', $start),
        date('H:i', $start + $mins * 60),
        $mins,
        $labels[$state][0] ?? $state,
        $s['status'],
        $s['mentor_name'],
        (int)$s['mentor_id'],
        $s['mentee_name'],
        (int)$s['mentee_id'],
        ($s['missed_by'] && $s['missed_by'] !== 'none') ? $s['missed_by'] : '',
        $s['completed_at'] ? date('Y-m-d H:i', strtotime($s['completed_at'])) : '',
        $s['rejection_reason'],
        $menteeRated[$id] ?? '',
        $mentorRated[$id] ?? '',
    ]);
}

fclose($out);
exit;
