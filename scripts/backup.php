<?php
/**
 * scripts/backup.php — back up PeerConnect's database and uploaded files.
 *
 *     php scripts/backup.php                    back up now, into pc_backup_dir()
 *     php scripts/backup.php --dir=D:\Backups   back up somewhere else
 *     php scripts/backup.php --verify=FILE      check an existing dump is complete; writes nothing
 *
 * Windows Task Scheduler runs it every night at 02:00 — see scripts/README.md.
 *
 * What it writes, inside the backup folder (C:\PeerConnectBackups unless
 * BACKUP_DIR is set in .env):
 *
 *   database\cs_YYYY-MM-DD_HHMMSS.sql       every run; the newest 30 are kept
 *   uploads\uploads_YYYY-MM-DD_HHMMSS.zip   once the newest is 7 days old; newest 4 kept
 *   logs\backup.log                         one line per step
 *
 * The database dump holds every member's email and password hash, and the
 * uploads archive holds the scanned student IDs from verification. That is why
 * the folder sits outside the web root and outside git.
 *
 * A file only gets its final name after it has been checked. A run that dies
 * halfway leaves a .partial file, which retention cleans up and the admin
 * Backup page ignores, instead of something that looks like a good backup.
 *
 * Exit code 0 means the backup was written and verified, 1 means it failed.
 * Task Scheduler shows the exit code as the task's "Last Run Result".
 */

if (PHP_SAPI !== 'cli') {
    // .htaccess blocks scripts/ as well; this is the second lock.
    http_response_code(404);
    exit;
}

$ROOT = dirname(__DIR__);
require $ROOT . '/App/config/db.php';   // $con, .env, pc_backup_dir()

const KEEP_DUMPS         = 30;
const KEEP_UPLOADS       = 4;
const UPLOADS_EVERY_DAYS = 7;

// File names and log lines follow the database's clock, not PHP's.
date_default_timezone_set('Asia/Manila');

$opts = getopt('', ['dir:', 'verify:']);

/* ── Helpers ──────────────────────────────────────────────────────────── */

$LOG = null;

function logline(string $msg): void
{
    global $LOG;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    if ($LOG !== null) {
        @file_put_contents($LOG, $line, FILE_APPEND | LOCK_EX);
    }
}

function fail_backup(string $msg): never
{
    logline('FAILED: ' . $msg);
    exit(1);
}

function human_size(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}

/**
 * Null when $file is a complete mysqldump, otherwise what is wrong with it.
 *
 * mysqldump's last line is "-- Dump completed on <date>". A dump that was cut
 * short — disk full, MySQL stopped, the laptop went to sleep — does not have it.
 */
function dump_problem(string $file, int $expectedTables = 0): ?string
{
    if (!is_file($file)) {
        return 'file not found';
    }
    $size = filesize($file);
    if ($size < 1024) {
        return "only $size bytes";
    }

    $fh = fopen($file, 'rb');
    fseek($fh, max(0, $size - 512));
    $tail = (string)stream_get_contents($fh);
    fclose($fh);
    if (!preg_match('/-- Dump completed on \d{4}-\d{2}-\d{2}/', $tail)) {
        return 'it has no "Dump completed" line at the end, so it was cut short';
    }

    // Views are dumped behind a /*!50001 guard, so this counts base tables only.
    $tables = 0;
    $fh = fopen($file, 'rb');
    while (($line = fgets($fh)) !== false) {
        if (strncmp($line, 'CREATE TABLE ', 13) === 0) {
            $tables++;
        }
    }
    fclose($fh);

    if ($tables === 0) {
        return 'it contains no tables';
    }
    if ($expectedTables > 0 && $tables < $expectedTables) {
        return "it has $tables tables but the database has $expectedTables";
    }
    return null;
}

/** Deletes all but the newest $keep files matching $pattern. Names sort by date. */
function prune(string $pattern, int $keep): array
{
    $files = glob($pattern) ?: [];
    rsort($files, SORT_STRING);
    $gone = [];
    foreach (array_slice($files, $keep) as $f) {
        if (@unlink($f)) {
            $gone[] = basename($f);
        }
    }
    return $gone;
}

/* ── --verify: check one file and stop ────────────────────────────────── */

if (isset($opts['verify'])) {
    $problem = dump_problem((string)$opts['verify']);
    echo $problem === null
        ? "OK   {$opts['verify']} is a complete dump.\n"
        : "BAD  {$opts['verify']}: $problem.\n";
    exit($problem === null ? 0 : 1);
}

/* ── Folders and log ──────────────────────────────────────────────────── */

$started = microtime(true);
$dir     = rtrim((string)($opts['dir'] ?? pc_backup_dir()), '\\/');
$ds      = DIRECTORY_SEPARATOR;

foreach (['', $ds . 'database', $ds . 'uploads', $ds . 'logs'] as $sub) {
    if (!is_dir($dir . $sub) && !@mkdir($dir . $sub, 0700, true)) {
        // Nowhere to log yet, so the console and the exit code have to do.
        echo '[' . date('Y-m-d H:i:s') . "] FAILED: cannot create {$dir}{$sub}\n";
        exit(1);
    }
}

$LOG = $dir . $ds . 'logs' . $ds . 'backup.log';
if (is_file($LOG) && filesize($LOG) > 1048576) {
    @rename($LOG, $LOG . '.1');   // keep one old log, drop anything older
}

try {
    logline("Backup started into $dir");

    // Leftovers from runs that died halfway.
    foreach (array_merge(glob("$dir{$ds}database{$ds}*.partial") ?: [], glob("$dir{$ds}uploads{$ds}*.partial") ?: []) as $old) {
        if (filemtime($old) < time() - 86400 && @unlink($old)) {
            logline('Removed an unfinished file from an earlier run: ' . basename($old));
        }
    }

    /* ── Database ─────────────────────────────────────────────────────── */

    $dbHost = (string)($_ENV['DB_HOST'] ?? 'localhost');
    $dbUser = (string)($_ENV['DB_USER'] ?? 'root');
    $dbPass = (string)($_ENV['DB_PASS'] ?? '');
    $dbName = (string)($_ENV['DB_NAME'] ?? 'cs');

    $mysqldump = trim((string)($_ENV['MYSQLDUMP_PATH'] ?? getenv('MYSQLDUMP_PATH') ?: ''));
    if ($mysqldump === '') {
        $xampp     = dirname(PHP_BINARY, 2) . $ds . 'mysql' . $ds . 'bin' . $ds . 'mysqldump.exe';
        $mysqldump = is_file($xampp) ? $xampp : 'mysqldump';
    }

    $expectedTables = (int)$con->query("SELECT COUNT(*) FROM information_schema.TABLES
                                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetch_row()[0];

    $final   = $dir . $ds . 'database' . $ds . $dbName . '_' . date('Y-m-d_His') . '.sql';
    $partial = $final . '.partial';

    $cmd      = [$mysqldump];
    $credFile = null;
    if ($dbPass !== '') {
        // A password on the command line is visible to anything that lists
        // processes. A defaults file that exists only while the dump runs is not.
        $credFile = tempnam(sys_get_temp_dir(), 'pcdump');
        file_put_contents($credFile, "[client]\npassword=\"" . addcslashes($dbPass, "\\\"") . "\"\n");
        $cmd[] = '--defaults-extra-file=' . $credFile;   // has to be the first option
    }
    array_push($cmd,
        '--host=' . $dbHost,
        '--user=' . $dbUser,
        '--single-transaction',          // a consistent snapshot without locking the site
        '--routines', '--triggers', '--events',
        '--hex-blob',
        '--default-character-set=utf8mb4',
        '--result-file=' . $partial,
        $dbName
    );

    // An argument array skips the shell entirely, so no quoting to get wrong.
    // @: a failure to start is reported below, with the reason, instead of as a
    // raw warning that would also land in the PHP error log.
    $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        $why = error_get_last()['message'] ?? '';
        fail_backup("could not start $mysqldump" . ($why !== '' ? " ($why)" : ''));
    }
    stream_get_contents($pipes[1]);
    $stderr = trim((string)stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($credFile !== null) {
        @unlink($credFile);
    }

    if ($code !== 0) {
        @unlink($partial);
        fail_backup("mysqldump exited with code $code" . ($stderr !== '' ? ": $stderr" : ''));
    }

    $problem = dump_problem($partial, $expectedTables);
    if ($problem !== null) {
        @unlink($partial);
        fail_backup('the database dump is not usable: ' . $problem);
    }
    if (!@rename($partial, $final)) {
        fail_backup('could not rename ' . basename($partial));
    }
    logline(sprintf('Database: %s (%s, %d tables, verified complete)', basename($final), human_size(filesize($final)), $expectedTables));

    /* ── User files ───────────────────────────────────────────────────── */

    // Both places members' files live, not just the public one.
    //
    // This used to archive public/uploads alone, so storage/verification —
    // the scanned student IDs people send in to be approved — had exactly one
    // copy, on the same disk as the database it was meant to be backed up
    // alongside. Losing that disk lost the documents. storage/reports is
    // covered for the same reason.
    //
    // The archive keeps its uploads_*.zip name and folder so the admin Backup
    // page and the retention rule below go on finding it; what changed is that
    // entries are now prefixed with the folder they came from, so one archive
    // restores to two places without guesswork.
    $roots = [
        'uploads' => PUBLIC_PATH . $ds . 'uploads',
        'storage' => BASE_PATH . $ds . 'storage',
    ];
    $present = array_filter($roots, 'is_dir');

    $zips    = glob($dir . $ds . 'uploads' . $ds . 'uploads_*.zip') ?: [];
    rsort($zips, SORT_STRING);
    $ageDays = $zips ? (time() - filemtime($zips[0])) / 86400 : INF;

    if (!$present) {
        logline('Files: skipped, neither ' . implode(' nor ', $roots) . ' exists');
    } elseif ($ageDays < UPLOADS_EVERY_DAYS) {
        logline(sprintf('Files: newest archive is %.1f day(s) old; the next is due in %.1f day(s)', $ageDays, UPLOADS_EVERY_DAYS - $ageDays));
    } else {
        if (!class_exists('ZipArchive')) {
            fail_backup('the PHP zip extension is not enabled, so user files cannot be archived');
        }
        $zipFinal   = $dir . $ds . 'uploads' . $ds . 'uploads_' . date('Y-m-d_His') . '.zip';
        $zipPartial = $zipFinal . '.partial';

        $zip = new ZipArchive();
        if ($zip->open($zipPartial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            fail_backup('could not create ' . basename($zipPartial));
        }
        $added   = 0;
        $perRoot = [];
        foreach ($present as $name => $root) {
            $before = $added;
            $files  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $f) {
                if ($f->isFile()) {
                    $inside = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
                    $zip->addFile($f->getPathname(), $name . '/' . $inside);
                    $added++;
                }
            }
            $perRoot[] = $name . ' ' . ($added - $before);
        }

        // An empty archive is not worth writing, and ZipArchive::close() fails
        // on one anyway — which would have been reported as a backup failure.
        if ($added === 0) {
            $zip->close();
            @unlink($zipPartial);
            logline('Files: nothing to archive yet (' . implode(', ', array_keys($present)) . ' are empty)');
        } else {
            if (!$zip->close()) {
                @unlink($zipPartial);
                fail_backup('writing the user-files archive failed');
            }

            // Read it back: an archive that will not open, or is missing files, is not a backup.
            $check  = new ZipArchive();
            $opened = $check->open($zipPartial) === true;
            $inZip  = $opened ? $check->numFiles : -1;
            if ($opened) {
                $check->close();
            }
            if ($inZip !== $added) {
                @unlink($zipPartial);
                fail_backup("the user-files archive holds $inZip file(s), expected $added");
            }
            if (!@rename($zipPartial, $zipFinal)) {
                fail_backup('could not rename ' . basename($zipPartial));
            }
            logline(sprintf('Files: %s (%s, %d files — %s, verified)',
                basename($zipFinal), human_size(filesize($zipFinal)), $added, implode(', ', $perRoot)));
        }
    }

    /* ── Retention ────────────────────────────────────────────────────── */

    $gone = array_merge(
        prune($dir . $ds . 'database' . $ds . $dbName . '_????-??-??_??????.sql', KEEP_DUMPS),
        prune($dir . $ds . 'uploads' . $ds . 'uploads_????-??-??_??????.zip', KEEP_UPLOADS)
    );
    if ($gone) {
        logline('Retention: removed ' . count($gone) . ' old file(s): ' . implode(', ', $gone));
    }

    logline(sprintf('Backup finished OK in %.1fs', microtime(true) - $started));
    exit(0);
} catch (Throwable $e) {
    fail_backup($e->getMessage());
}
