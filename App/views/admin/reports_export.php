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

/*
 * ── Asked for as a document rather than a spreadsheet ──────────────────────
 *
 * Everything below this block is the CSV export, untouched. The PDF path
 * gathers what it needs itself and returns before any CSV header is sent:
 * one byte written to the output before the Content-Type would break both.
 *
 * "PDF" here means a letterheaded page the browser prints. See
 * includes/report_print.php for why that beats a PHP PDF library on an app
 * whose charts are already SVG.
 */
if (($_GET['format'] ?? 'csv') === 'pdf') {
    pc_admin_log('viewed the overall summary report as a document');

    $measures = [
        'New members'           => 'joined',
        'Active members'        => 'active',
        'Sessions'              => 'sessions',
        'Sessions completed'    => 'completed',
        'Assessments submitted' => 'assessments',
        'Messages sent'         => 'messages',
        'Feedback written'      => 'feedback',
        'Resources uploaded'    => 'resources',
        'Sign-ins by members'   => 'signins',
    ];

    $areas     = rp_areas($now);
    $areaTotal = array_sum(array_column($areas, 1));
    $roleMix   = SummaryRepository::roleMix($con);
    $B         = rp_buckets($from, $to);

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

    $subjects = [];
    foreach (SummaryRepository::subjects($con, $from, $to) as $row) $subjects[$row['subject']] = (int)$row['n'];

    $people    = SummaryRepository::signInPeople($con, $from, $to);
    $ratingRow = SummaryRepository::ratings($con, $from, $to);
    $concluded = SummaryRepository::concluded($con, $from, $to);
    $mail      = SummaryRepository::emailStatuses($con, $from, $to);

    $report_title    = 'Overall Summary Report';
    $report_subtitle = $R['key'] === 'all'
        ? 'All time — up to ' . date('j F Y', strtotime($to))
        : date('j F Y', strtotime($from)) . ' to ' . date('j F Y', strtotime($to));
    $report_back     = url('admin-reports');
    $report_meta     = [
        'Range'      => $R['key'] === 'all' ? 'All time' : $R['label'],
        'Days'       => $R['days'],
        'Compared to' => $prev ? date('j M Y', strtotime($R['prev'][0])) . ' – ' . date('j M Y', strtotime($R['prev'][1])) : 'no earlier period',
        'Generated'  => date('j M Y, H:i') . ' (Asia/Manila)',
        'By'         => pc_user_name($con, (int)($_SESSION['user_id'] ?? 0)) ?: 'an administrator',
    ];

    $report_body = function () use ($now, $prev, $measures, $areas, $areaTotal, $roleMix, $B, $reg, $subjects, $people, $ratingRow, $concluded, $mail) {

        $completion = $concluded > 0 ? round($now['completed'] / $concluded * 100) : null;
        rpt_tiles([
            ['label' => 'New members',      'value' => number_format($now['joined']),    'hint' => 'joined in this range'],
            ['label' => 'Sessions',         'value' => number_format($now['sessions']),  'hint' => 'booked in this range'],
            ['label' => 'Completed',        'value' => number_format($now['completed']), 'hint' => 'sessions concluded', 'tone' => 'ok'],
            ['label' => 'Completion rate',  'value' => $completion === null ? 'n/a' : $completion . '%',
             'hint' => $completion === null ? 'nothing concluded yet' : 'of concluded sessions',
             'tone' => $completion === null ? null : ($completion >= 70 ? 'ok' : ($completion >= 40 ? 'warn' : 'bad'))],
        ]);

        rpt_section('Headline figures', function () use ($measures, $now, $prev) {
            $rows = [];
            foreach ($measures as $label => $k) {
                $d = rp_delta($now[$k], $prev ? $prev[$k] : null);
                $change = $d === null ? 'n/a'
                    : ($d[0] === 'new' ? 'no earlier data' : ($d[0] === 'up' ? '+' : '') . $d[1] . '%');
                $rows[] = [
                    $label,
                    ['v' => number_format($now[$k]), 'num' => true],
                    ['v' => $prev ? number_format($prev[$k]) : '—', 'num' => true],
                    ['v' => $change, 'num' => true],
                ];
            }
            rpt_table(['Measure', ['v' => 'This range', 'num' => true], ['v' => 'Previous', 'num' => true], ['v' => 'Change', 'num' => true]], $rows);
        });

        rpt_section('Activity per ' . $B['unit'], function () use ($reg, $B) {
            $chart = [];
            foreach ($reg as $k => $v) {
                $chart[$k] = ['Mentees' => $v['mentee'], 'Mentors' => $v['mentor'], 'Sessions' => $v['sess'], 'Completed' => $v['done']];
            }
            echo '<div class="rpt-chart">';
            rpt_columns($chart, ['Mentees' => '#0b2d6b', 'Mentors' => '#087FC1', 'Sessions' => '#9A7100', 'Completed' => '#17654B']);
            echo '</div>';

            $rows = [];
            foreach ($reg as $k => $v) {
                $rows[] = [$k, ['v' => $v['mentee'], 'num' => true], ['v' => $v['mentor'], 'num' => true],
                           ['v' => $v['sess'], 'num' => true], ['v' => $v['done'], 'num' => true]];
            }
            rpt_table([ucfirst($B['unit']) . ' starting', ['v' => 'Mentees', 'num' => true], ['v' => 'Mentors', 'num' => true],
                       ['v' => 'Sessions', 'num' => true], ['v' => 'Completed', 'num' => true]], $rows);
        });

        rpt_section('Where the records came from', function () use ($areas, $areaTotal, $roleMix) {
            echo '<div class="rpt-charts">';
            echo '<div class="rpt-chart"><h3>Records created, by area</h3>';
            $bars = [];
            foreach ($areas as [$label, $n]) $bars[$label] = $n;
            rpt_bars($bars);
            echo '</div>';
            echo '<div class="rpt-chart"><h3>Accounts by role (all time)</h3>';
            rpt_bars(array_map('intval', $roleMix), '#087FC1');
            echo '</div></div>';

            $rows = [];
            foreach ($areas as [$label, $n]) {
                $rows[] = [$label, ['v' => $n, 'num' => true],
                           ['v' => $areaTotal > 0 ? round($n / $areaTotal * 100) . '%' : '0%', 'num' => true]];
            }
            rpt_table(['Area', ['v' => 'Records', 'num' => true], ['v' => 'Share', 'num' => true]], $rows);
        });

        rpt_section('Subjects booked in this range', function () use ($subjects) {
            if ($subjects) {
                echo '<div class="rpt-chart">';
                rpt_bars($subjects, '#17654B');
                echo '</div>';
            }
            $rows = [];
            foreach ($subjects as $s => $n) $rows[] = [$s, ['v' => $n, 'num' => true]];
            rpt_table(['Subject', ['v' => 'Sessions', 'num' => true]], $rows, 'No sessions were booked in this range.');
        });

        rpt_section('Engagement', function () use ($now, $people, $ratingRow, $concluded) {
            rpt_table(['Measure', ['v' => 'Value', 'num' => true]], [
                ['Sign-ins by members',     ['v' => number_format($now['signins']), 'num' => true]],
                ['Members who signed in',   ['v' => number_format((int)$people['people']), 'num' => true]],
                ['Came back on another day', ['v' => number_format((int)$people['returned']), 'num' => true]],
                ['Sessions that concluded', ['v' => number_format($concluded), 'num' => true]],
                ['Reviews written',         ['v' => number_format((int)$ratingRow['n']), 'num' => true]],
                ['Average rating out of 5', ['v' => (int)$ratingRow['n'] > 0 ? round((float)$ratingRow['a'], 2) : 'n/a', 'num' => true]],
            ]);
        });

        rpt_section('Notification email in this range', function () use ($mail) {
            $rows = [];
            foreach ($mail as $status => $n) $rows[] = [ucfirst((string)$status), ['v' => $n, 'num' => true]];
            rpt_table(['Status', ['v' => 'Notifications', 'num' => true]], $rows, 'No notifications were raised in this range.');
        });

        // Carried over from the CSV, and worth more in a document somebody may
        // present: the reader can see what the platform does not claim to know.
        rpt_section('Not measured by this platform', function () {
            rpt_table(['Measure', 'Why it is absent'], [
                ['Average time on site', 'No page views are recorded'],
                ['Pages per visit',      'No page views are recorded'],
                ['Uptime',               'No monitor runs against this install'],
                ['Average response time', 'No monitor runs against this install'],
            ]);
        });
    };

    require __DIR__ . '/includes/report_print.php';
    exit;
}

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
