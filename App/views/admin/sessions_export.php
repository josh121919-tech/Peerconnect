<?php

/**
 * sessions_export.php — the session list, as a file.
 *
 * Two formats. ?format=csv (the default) is the spreadsheet this has always
 * produced, and opens in Excel or Sheets. ?format=pdf is a letterheaded
 * document the browser prints — see includes/report_print.php for why that
 * beats vendoring a PDF library on an app whose charts are already SVG.
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

// Only a tab the list page has; anything else exports everything. The tab
// also names the file, so an unchecked value would end up in a header.
$view    = in_array(ad_query('tab'), array_keys(ad_session_states()), true) ? ad_query('tab') : 'all';
$q       = trim(ad_query('q'));
$type    = in_array($_GET['type'] ?? '', ['1v1', 'group'], true) ? $_GET['type'] : '';
$subject = trim(ad_query('subject'));
$club    = trim(ad_query('club'));
$from    = trim(ad_query('from'));
$to      = trim(ad_query('to'));

$rows = AdminSessionRepository::allMatching($con, [
    'q'       => $q,
    'subject' => $subject,
    'club'    => $club,
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

/*
 * ── Asked for as a document rather than a spreadsheet ──────────────────────
 *
 * Returns before any CSV header is sent; everything below is the CSV export,
 * untouched.
 *
 * The CSV carries twenty columns. Twenty will not fit across A4 portrait at a
 * readable size, so the document shows the ones somebody reads a session list
 * for and says plainly where the rest are, rather than shrinking the type
 * until none of it can be read.
 */
if (($_GET['format'] ?? 'csv') === 'pdf') {
    pc_admin_log('viewed ' . count($rows) . ' session' . (count($rows) === 1 ? '' : 's') . ' as a document');

    $byState = [];
    foreach ($rows as $s) {
        $st = $labels[ad_session_state($s)][0] ?? ad_session_state($s);
        $byState[$st] = ($byState[$st] ?? 0) + 1;
    }
    arsort($byState);

    $report_title    = 'Sessions Report';
    $report_subtitle = $view === 'all' ? 'All sessions' : 'Filtered: ' . ($labels[$view][0] ?? $view);
    $report_back     = url('admin-sessions');
    $report_meta     = [
        'Sessions'  => count($rows),
        'Filter'    => $view === 'all' ? 'none' : ($labels[$view][0] ?? $view),
        'Generated' => date('j M Y, H:i') . ' (Asia/Manila)',
        'By'        => pc_user_name($con, (int)($_SESSION['user_id'] ?? 0)) ?: 'an administrator',
    ];

    $report_body = function () use ($rows, $byState, $labels, $menteeRated, $mentorRated) {
        $tiles = [['label' => 'Sessions', 'value' => number_format(count($rows)), 'hint' => 'in this export']];
        foreach (array_slice($byState, 0, 3, true) as $state => $n) {
            $tiles[] = ['label' => $state, 'value' => number_format($n),
                        'tone' => stripos($state, 'complete') !== false ? 'ok'
                            : (stripos($state, 'miss') !== false || stripos($state, 'cancel') !== false ? 'bad' : null)];
        }
        rpt_tiles($tiles);

        rpt_section('Sessions by state', function () use ($byState) {
            echo '<div class="rpt-chart">';
            rpt_bars($byState);
            echo '</div>';
            $t = array_sum($byState);
            $r = [];
            foreach ($byState as $state => $n) {
                $r[] = [$state, ['v' => $n, 'num' => true], ['v' => $t > 0 ? round($n / $t * 100) . '%' : '0%', 'num' => true]];
            }
            rpt_table(['State', ['v' => 'Sessions', 'num' => true], ['v' => 'Share', 'num' => true]], $r);
        });

        rpt_section('Session list', function () use ($rows, $labels, $menteeRated, $mentorRated) {
            $r = [];
            foreach ($rows as $s) {
                $id = (int)$s['request_id'];
                $r[] = [
                    ad_session_ref($id, $s['session_date']),
                    $s['subject'],
                    ($s['session_type'] ?? '') === 'group' ? 'Group' : '1-on-1',
                    date('j M Y, H:i', strtotime($s['session_date'])),
                    ['v' => ad_session_minutes($s), 'num' => true],
                    $labels[ad_session_state($s)][0] ?? ad_session_state($s),
                    $s['mentor_name'] ?: '—',
                    $s['mentee_name'] ?: '—',
                ];
            }
            rpt_table(['Reference', 'Subject', 'Type', 'When', ['v' => 'Mins', 'num' => true], 'State', 'Mentor', 'Mentee'],
                $r, 'No sessions match the current filter.');
            echo '<p class="rpt-empty" style="margin-top:3mm;text-align:left;">'
                . 'Topics, raw status, who missed it, when it closed, the reason given and both ratings '
                . 'are in the CSV export of this same selection.</p>';
        });
    };

    require __DIR__ . '/includes/report_print.php';
    exit;
}

// It holds names and session details, so who took a copy is worth a record.
// Logged here rather than above the format branch: a PDF view was writing
// this line as well as its own, so the audit trail claimed a CSV had been
// downloaded when none had.
pc_admin_log('exported ' . count($rows) . ' session' . (count($rows) === 1 ? '' : 's') . ' (' . $name . ')');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
// Excel reads a CSV as the system codepage unless the file says otherwise;
// without this, a name with an accent in it arrives mangled.
fwrite($out, "\xEF\xBB\xBF");

CsvExport::row($out, [
    'Reference', 'Session ID', 'Subject', 'Topics', 'Type', 'Date', 'Start', 'End',
    'Duration (minutes)', 'State', 'Raw status', 'Mentor', 'Mentor ID', 'Mentee', 'Mentee ID',
    'Missed by', 'Closed at', 'Reason given', 'Mentee rated mentor', 'Mentor rated mentee',
]);

foreach ($rows as $s) {
    $id    = (int)$s['request_id'];
    $mins  = ad_session_minutes($s);
    $start = strtotime($s['session_date']);
    $state = ad_session_state($s);

    CsvExport::row($out, [
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
