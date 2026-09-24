<?php

/**
 * settings_logs_export.php — the activity log as a file.
 *
 * Exports exactly what the filters on screen select, so the file matches the
 * view it was taken from. CSV, for the same reason as everywhere else here:
 * nothing in this install can render a PDF.
 *
 * SECURITY: admin-only, prepared statements.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$kind = $_GET['kind'] ?? 'all';
$q    = trim((string)($_GET['q'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to'] ?? ''));

$clauses = [];
$types   = '';
$args    = [];

$kindSql = [
    'login'    => "l.activity LIKE 'user login%'",
    'logout'   => "l.activity LIKE '%logout%'",
    'signup'   => "l.activity LIKE '%signup%'",
    'password' => "l.activity LIKE 'password reset%'",
    'admin'    => "l.activity LIKE 'admin%'",
][$kind] ?? '';
if ($kindSql !== '') $clauses[] = $kindSql;

if ($q !== '') {
    $clauses[] = "CONCAT_WS(' ', l.email, l.activity, u.firstname, u.lastname) LIKE ?";
    $types .= 's'; $args[] = '%' . $q . '%';
}
if ($from !== '' && strtotime($from)) { $clauses[] = 'l.log_date >= ?'; $types .= 's'; $args[] = date('Y-m-d', strtotime($from)); }
if ($to !== '' && strtotime($to))     { $clauses[] = 'l.log_date < ? + INTERVAL 1 DAY'; $types .= 's'; $args[] = date('Y-m-d', strtotime($to)); }

$where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

$sql = "
    SELECT l.log_id, l.log_date, l.email, l.activity,
           CONCAT_WS(' ', u.firstname, u.lastname) AS name, u.role, u.user_id
    FROM logs l
    LEFT JOIN users u  ON u.email = l.email
    $where
    ORDER BY l.log_id DESC
";
if ($types !== '') {
    $st = $con->prepare($sql);
    $st->bind_param($types, ...$args);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
} else {
    $rows = $con->query($sql)->fetch_all(MYSQLI_ASSOC);
}

/*
 * ── Asked for as a document rather than a spreadsheet ──────────────────────
 *
 * Returns before any CSV header is sent; the CSV export below is untouched.
 * The log line is written inside each branch, so the record says which of the
 * two actually happened.
 */
if (($_GET['format'] ?? 'csv') === 'pdf') {
    // After the query, so the document does not contain its own entry.
    pc_admin_log('viewed ' . count($rows) . ' activity log entr' . (count($rows) === 1 ? 'y' : 'ies') . ' as a document');

    $byRole = [];
    $byDay  = [];
    foreach ($rows as $r) {
        $role = $r['role'] ?: 'unknown';
        $byRole[$role] = ($byRole[$role] ?? 0) + 1;
        $d = date('Y-m-d', strtotime($r['log_date']));
        $byDay[$d] = ($byDay[$d] ?? 0) + 1;
    }
    arsort($byRole);
    ksort($byDay);

    $report_title    = 'Activity Log';
    $report_subtitle = $rows
        ? date('j M Y', strtotime(end($rows)['log_date'])) . ' to ' . date('j M Y', strtotime($rows[0]['log_date']))
        : 'No entries match the current filter';
    $report_back     = url('admin-settings-logs');
    $report_meta     = [
        'Entries'   => count($rows),
        'Generated' => date('j M Y, H:i') . ' (Asia/Manila)',
        'By'        => pc_user_name($con, (int)($_SESSION['user_id'] ?? 0)) ?: 'an administrator',
    ];

    $report_body = function () use ($rows, $byRole, $byDay) {
        $tiles = [['label' => 'Entries', 'value' => number_format(count($rows)), 'hint' => 'in this export']];
        foreach (array_slice($byRole, 0, 3, true) as $role => $n) {
            $tiles[] = ['label' => ucfirst((string)$role), 'value' => number_format($n), 'hint' => 'entries'];
        }
        rpt_tiles($tiles);

        if ($byDay) {
            rpt_section('Entries per day', function () use ($byDay) {
                echo '<div class="rpt-chart">';
                rpt_columns(array_map(fn($n) => ['Entries' => $n], $byDay), ['Entries' => '#0b2d6b']);
                echo '</div>';
            });
        }

        rpt_section('Who was active', function () use ($byRole) {
            echo '<div class="rpt-chart">';
            rpt_bars($byRole, '#087FC1');
            echo '</div>';
        });

        rpt_section('Entries', function () use ($rows) {
            $r = [];
            foreach ($rows as $row) {
                $ts = strtotime($row['log_date']);
                $r[] = [
                    ['v' => (int)$row['log_id'], 'num' => true],
                    date('j M Y, H:i', $ts),
                    $row['name'] ?: '—',
                    $row['email'],
                    $row['role'] ?: '—',
                    $row['activity'],
                ];
            }
            rpt_table([['v' => '#', 'num' => true], 'When', 'Name', 'Email', 'Role', 'Activity'],
                $r, 'No activity matches the current filter.');
        });
    };

    require __DIR__ . '/includes/report_print.php';
    exit;
}

// After the query, so the file does not contain its own entry.
pc_admin_log('exported ' . count($rows) . ' activity log entr' . (count($rows) === 1 ? 'y' : 'ies'));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="peerconnect-activity-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
CsvExport::row($out, ['Log ID', 'Date', 'Time', 'Name', 'Email', 'Role', 'User ID', 'Activity']);
foreach ($rows as $r) {
    $ts = strtotime($r['log_date']);
    CsvExport::row($out, [
        (int)$r['log_id'],
        date('Y-m-d', $ts),
        date('H:i:s', $ts),
        $r['name'] ?: '',
        $r['email'],
        $r['role'] ?: '',
        $r['user_id'] ? (int)$r['user_id'] : '',
        $r['activity'],
    ]);
}
fclose($out);
exit;
