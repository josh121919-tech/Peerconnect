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
    LEFT JOIN emails e ON e.email = l.email
    LEFT JOIN users u  ON u.user_id = e.user_id
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

// After the query, so the file does not contain its own entry.
pc_admin_log('exported ' . count($rows) . ' activity log entr' . (count($rows) === 1 ? 'y' : 'ies'));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="peerconnect-activity-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Log ID', 'Date', 'Time', 'Name', 'Email', 'Role', 'User ID', 'Activity']);
foreach ($rows as $r) {
    $ts = strtotime($r['log_date']);
    fputcsv($out, [
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
