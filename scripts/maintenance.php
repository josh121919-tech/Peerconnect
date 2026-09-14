<?php
/**
 * scripts/maintenance.php — the every-30-minutes job.
 *
 *     php scripts/maintenance.php             run it now
 *     php scripts/maintenance.php --dry-run   list what would be marked missed; write nothing
 *
 * Windows Task Scheduler runs it every 30 minutes — see scripts/README.md.
 *
 * 1. Missed sessions. Runs App/views/cron/detect_missed_sessions.php, the same
 *    code as the button on Platform analytics. An approved session that ended
 *    more than PC_MISSED_GRACE_HOURS ago and was never closed is marked missed,
 *    and both people are notified, by email too when it is switched on.
 *
 * 2. Mentor scores. MentorScoreService::refreshAll. Nothing refreshed them on
 *    a schedule before, so the leaderboard recalculated every mentor's score
 *    itself whenever it was opened more than an hour after the last refresh.
 *    With this running, that fallback has nothing left to do.
 *
 * Output goes to the console and to logs\maintenance.log in the backup folder.
 * Exit code 0 means both steps ran; anything else means something failed.
 */

if (PHP_SAPI !== 'cli') {
    // .htaccess blocks scripts/ as well; this is the second lock.
    http_response_code(404);
    exit;
}

$ROOT = dirname(__DIR__);
require_once $ROOT . '/App/config/db.php';
require_once $ROOT . '/App/services/MentorScoreService.php';

date_default_timezone_set('Asia/Manila');

$LOG_DIR = pc_backup_dir() . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($LOG_DIR)) {
    @mkdir($LOG_DIR, 0700, true);
}
$LOG_FILE = $LOG_DIR . DIRECTORY_SEPARATOR . 'maintenance.log';
if (is_file($LOG_FILE) && filesize($LOG_FILE) > 1048576) {
    @rename($LOG_FILE, $LOG_FILE . '.1');   // keep one old log, drop anything older
}

function maintenance_log(string $text): void
{
    global $LOG_FILE;
    echo $text;
    @file_put_contents($LOG_FILE, $text, FILE_APPEND | LOCK_EX);
}

// If anything below dies — an exception, a fatal error — say so in the log.
// Otherwise a job that fails every half hour would leave no trace at all.
$MAINTENANCE_DONE = false;
register_shutdown_function(function (): void {
    global $MAINTENANCE_DONE;
    if ($MAINTENANCE_DONE) {
        return;
    }
    $e = error_get_last();
    maintenance_log('[' . date('Y-m-d H:i:s') . '] FAILED: '
        . ($e ? $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')' : 'stopped before finishing')
        . PHP_EOL);
});

$dryRun = in_array('--dry-run', $argv, true);

/* 1. Missed sessions — the detector prints its own one-line summary. */
ob_start();
require $ROOT . '/App/views/cron/detect_missed_sessions.php';
maintenance_log((string)ob_get_clean());

/* 2. Mentor scores. */
if ($dryRun) {
    maintenance_log('[' . date('Y-m-d H:i:s') . '] Dry run: mentor scores not refreshed.' . PHP_EOL);
} else {
    $refreshed = MentorScoreService::refreshAll($con);
    maintenance_log('[' . date('Y-m-d H:i:s') . "] Refreshed scores for $refreshed mentor(s)." . PHP_EOL);
}

$MAINTENANCE_DONE = true;
exit(0);
