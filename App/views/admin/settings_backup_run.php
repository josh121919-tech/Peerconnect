<?php

/**
 * settings_backup_run.php — the SQL dump itself.
 *
 * Written in PHP rather than shelling out to mysqldump, which is not
 * guaranteed to be on PATH in a XAMPP install and would need shell_exec
 * enabled. Streamed as it is built, so a large database does not have to fit
 * in memory and nothing is left on the server afterwards.
 *
 * SECURITY: admin-only, POST-only, CSRF-checked. Table and column names come
 * from information_schema and are back-quoted; every value is escaped.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_admin();
require_post();

$expected = $_SESSION['csrf_token'] ?? '';
$given    = $_POST['csrf_token'] ?? '';
if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    exit('CSRF token mismatch.');
}

date_default_timezone_set('Asia/Manila');
@set_time_limit(300);

$dbName = (string)$con->query("SELECT DATABASE()")->fetch_row()[0];
$file   = 'peerconnect-backup-' . date('Y-m-d-Hi') . '.sql';

// Logged before streaming starts: the file holds every account's data,
// including password hashes, so the attempt is worth a record even if the
// download is interrupted.
pc_admin_log('downloaded a full database backup (' . $file . ')');

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Cache-Control: no-store');

// Nothing may buffer this — a large dump would otherwise sit in memory.
while (ob_get_level() > 0) ob_end_flush();

$out = fopen('php://output', 'w');

fwrite($out, "-- PeerConnect backup\n");
fwrite($out, "-- Database: {$dbName}\n");
fwrite($out, "-- Taken:    " . date('Y-m-d H:i:s') . " (Asia/Manila)\n");
fwrite($out, "-- Restore:  mysql -u root {$dbName} < {$file}\n");
fwrite($out, "--\n");
fwrite($out, "-- Note: uploaded files in public/uploads are NOT in this file.\n\n");

fwrite($out, "SET NAMES utf8mb4;\n");
fwrite($out, "SET FOREIGN_KEY_CHECKS = 0;\n");
fwrite($out, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

$tables = [];
$tq = $con->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
while ($r = $tq->fetch_row()) $tables[] = $r[0];

foreach ($tables as $t) {
    $qt = '`' . str_replace('`', '``', $t) . '`';

    fwrite($out, "\n-- ─────────────────────────────────────────────\n");
    fwrite($out, "-- Table: {$t}\n");
    fwrite($out, "-- ─────────────────────────────────────────────\n");
    fwrite($out, "DROP TABLE IF EXISTS {$qt};\n");

    $create = $con->query("SHOW CREATE TABLE {$qt}")->fetch_row()[1] ?? '';
    fwrite($out, $create . ";\n\n");

    // Streamed, not buffered: a big table must not be pulled into PHP memory
    // in one piece just to be written straight back out again.
    $res = $con->query("SELECT * FROM {$qt}", MYSQLI_USE_RESULT);
    if (!$res) continue;

    $cols = [];
    foreach ($res->fetch_fields() as $f) $cols[] = '`' . str_replace('`', '``', $f->name) . '`';
    $colList = implode(', ', $cols);

    $batch = [];
    $rows  = 0;
    while ($row = $res->fetch_row()) {
        $vals = [];
        foreach ($row as $v) {
            $vals[] = $v === null ? 'NULL' : "'" . $con->real_escape_string((string)$v) . "'";
        }
        $batch[] = '(' . implode(',', $vals) . ')';
        $rows++;

        // Chunked so one INSERT never exceeds max_allowed_packet on restore.
        if (count($batch) >= 200) {
            fwrite($out, "INSERT INTO {$qt} ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n");
            $batch = [];
        }
    }
    if ($batch) {
        fwrite($out, "INSERT INTO {$qt} ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n");
    }
    $res->free();

    fwrite($out, "-- {$rows} row" . ($rows === 1 ? '' : 's') . " in {$t}\n");
}

/*
 * Views, after every table they read from exists.
 *
 * These are easy to forget — SHOW FULL TABLES lists them alongside real
 * tables, but SHOW CREATE TABLE on one produces a CREATE VIEW that cannot run
 * until its underlying tables are there. Restoring without them leaves the app
 * missing an object it queries, which fails at the point of use rather than at
 * the point of restore.
 */
$views = [];
$vq = $con->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
while ($r = $vq->fetch_row()) $views[] = $r[0];

foreach ($views as $v) {
    $qv = '`' . str_replace('`', '``', $v) . '`';
    fwrite($out, "\n-- ─────────────────────────────────────────────\n");
    fwrite($out, "-- View: {$v}\n");
    fwrite($out, "-- ─────────────────────────────────────────────\n");
    fwrite($out, "DROP VIEW IF EXISTS {$qv};\n");

    $create = $con->query("SHOW CREATE VIEW {$qv}")->fetch_row()[1] ?? '';
    // The DEFINER clause names a MySQL account that may not exist wherever
    // this is restored, and it is not needed for the view to work.
    $create = preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $create);
    $create = preg_replace('/SQL SECURITY DEFINER\s*/', 'SQL SECURITY INVOKER ', $create);
    fwrite($out, $create . ";\n");
}

fwrite($out, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
fwrite($out, '-- ' . count($tables) . ' table' . (count($tables) === 1 ? '' : 's')
           . ', ' . count($views) . ' view' . (count($views) === 1 ? '' : 's') . ".\n");
fwrite($out, "-- End of backup.\n");
fclose($out);
exit;
