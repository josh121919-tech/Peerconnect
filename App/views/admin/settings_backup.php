<?php

/**
 * admin/settings_backup.php — System Settings → Backup & Restore.
 *
 * Backup is real: it writes a genuine SQL dump of every table, generated in
 * PHP so it needs no mysqldump on PATH, and streams it as a download.
 *
 * Scheduled backups are written by scripts/backup.php into pc_backup_dir().
 * This page reads that folder and reports only what is actually there: it
 * cannot see Task Scheduler, so it never claims that a schedule exists.
 *
 * Restore is deliberately not offered. Uploading a SQL file through a web
 * form and executing it is the single most destructive thing this panel could
 * do — one bad file and every account, session and message is gone, with the
 * backup that would have saved you overwritten in the same motion. It belongs
 * at a command line where the person running it can see what they are doing.
 * The page says so, and gives the exact command instead of hiding it.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/settings_store.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$csrf = csrf_token();

$one = function (string $sql) use ($con) {
    $r = $con->query($sql);
    return $r ? $r->fetch_row()[0] : null;
};

$dbName = (string)$one("SELECT DATABASE()");

/* Every table, with its row count and size — a real inventory. */
$tables = $con->query("
    SELECT table_name AS t, table_rows AS n, (data_length + index_length) AS bytes
    FROM information_schema.TABLES
    WHERE table_schema = DATABASE()
    ORDER BY (data_length + index_length) DESC
")->fetch_all(MYSQLI_ASSOC);

$dbBytes = array_sum(array_column($tables, 'bytes'));
$tableN  = count($tables);

// table_rows is an estimate on InnoDB, so the headline count is exact for the
// tables that matter and the per-table figure is labelled as approximate.
$exactRows = 0;
foreach (['users', 'session_requests', 'messages', 'notifications', 'feedback'] as $t) {
    $exactRows += (int)$one("SELECT COUNT(*) FROM `$t`");
}

function bk_size(int $b): string
{
    if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576)    return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024)       return round($b / 1024) . ' KB';
    return $b . ' B';
}

/* Uploaded files are the other half of a backup, and are not in the dump. */
function bk_dir(string $dir): array
{
    if (!is_dir($dir)) return [0, 0];
    $n = 0; $b = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile()) { $n++; $b += $f->getSize(); }
    return [$n, $b];
}
[$fileN, $fileBytes] = bk_dir(PUBLIC_PATH . '/uploads');

/* ── Scheduled backups: what is actually on disk ────────────────────────── */
$bkDir   = pc_backup_dir();
$bkDs    = DIRECTORY_SEPARATOR;
$bkDumps = glob($bkDir . $bkDs . 'database' . $bkDs . '*_????-??-??_??????.sql') ?: [];
$bkZips  = glob($bkDir . $bkDs . 'uploads' . $bkDs . 'uploads_????-??-??_??????.zip') ?: [];
rsort($bkDumps, SORT_STRING);   // names carry the timestamp, so this is newest first
rsort($bkZips, SORT_STRING);
$bkLastAt = $bkDumps ? filemtime($bkDumps[0]) : null;
$bkZipAt  = $bkZips ? filemtime($bkZips[0]) : null;
$bkStale  = $bkLastAt !== null && time() - $bkLastAt > 36 * 3600;   // a nightly job, plus slack

// The newest file can be a good backup from days ago while last night's attempt
// failed. Only the last line of the log tells those two apart.
$bkLastLine = '';
$bkLog = $bkDir . $bkDs . 'logs' . $bkDs . 'backup.log';
if (is_file($bkLog) && ($bkFh = @fopen($bkLog, 'rb'))) {
    fseek($bkFh, max(0, filesize($bkLog) - 2048));
    $bkLines = array_values(array_filter(array_map('trim', explode("\n", (string)stream_get_contents($bkFh)))));
    fclose($bkFh);
    $bkLastLine = (string)end($bkLines);
}
$bkFailed = str_contains($bkLastLine, 'FAILED:');

function bk_when(int $ts): string
{
    $mins = max(0, (int)floor((time() - $ts) / 60));
    $ago  = $mins < 60 ? $mins . ' min ago'
          : ($mins < 2880 ? floor($mins / 60) . ' h ago' : floor($mins / 1440) . ' days ago');
    return date('M j, g:i A', $ts) . ' · ' . $ago;
}

$current_page = 'settings-backup';
include 'layout.php';
include __DIR__ . '/includes/settings_ui.php';
?>

<?php ob_start(); ?>
<form method="post" action="<?= url('admin-settings-backup-run') ?>" style="margin:0;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <button type="submit" class="ss-export" style="border:0;cursor:pointer;font-family:inherit;">
        <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0 4-4m-4 4-4-4M4 19h16" /></svg>
        Download backup
    </button>
</form>
<?php $actions = ob_get_clean(); ?>

<?php st_header('admin-settings-backup', 'Backup & Restore',
    'Take a copy of the database you can keep somewhere safe.', $actions); ?>

<div class="ss-stats">
    <?php foreach ([
        ['Database', bk_size((int)$dbBytes), $tableN . ' tables in ' . $dbName, '#EAF1FB', '#1A5C9A', 'chart'],
        ['Core records', number_format($exactRows), 'Users, sessions, messages, feedback', '#E6F5EE', '#17654B', 'check'],
        ['Uploaded files', number_format($fileN), bk_size((int)$fileBytes) . ' in public/uploads', '#EAF6FC', '#087FC1', 'cal'],
        $bkLastAt !== null
            ? ['Last automatic backup', date('M j, g:i A', $bkLastAt), count($bkDumps) . ' kept in ' . $bkDir,
               ($bkStale || $bkFailed) ? '#FEF6DC' : '#E6F5EE', ($bkStale || $bkFailed) ? '#7A5A00' : '#17654B', 'clock']
            : ['Last automatic backup', 'None yet', 'Nothing in ' . $bkDir, '#F3F4F6', '#565B66', 'clock'],
    ] as [$k, $v, $s, $bg, $fg, $ico]): ?>
        <div class="ss-stat">
            <span class="ss-stat-ico" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= ss_icon($ico) ?></span>
            <div style="min-width:0;">
                <div class="ss-stat-k"><?= $k ?></div>
                <div class="ss-stat-v"><?= htmlspecialchars($v) ?></div>
                <div class="ss-stat-s"><?= htmlspecialchars($s) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="st-grid">
    <div class="st-stack">
        <div class="st-card">
            <h2>Take a backup</h2>
            <p class="sub">A complete SQL dump of every table, streamed straight to your machine.</p>

            <div class="st-note">
                <b>What is in it.</b>
                The structure and every row of all <?= $tableN ?> tables, in the order they can be restored in.
                It is generated in PHP, so it does not need <code>mysqldump</code> installed, and it is never
                written to the server — the file goes straight to your browser and nothing is left behind.
            </div>

            <div class="st-note warn">
                <b>What is not in it.</b>
                Uploaded files. Profile photos, verification documents, resources and announcement banners live
                in <code>public/uploads</code> (<?= number_format($fileN) ?> files, <?= bk_size((int)$fileBytes) ?>)
                and have to be copied separately. A database restored without them will show broken images.
                The scheduled backup archives them once a week; a download from this page does not.
            </div>

            <form method="post" action="<?= url('admin-settings-backup-run') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" class="st-save">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0 4-4m-4 4-4-4M4 19h16" /></svg>
                    Download <?= htmlspecialchars($dbName) ?>.sql
                </button>
            </form>
        </div>

        <div class="st-card">
            <h2>Restoring</h2>
            <p class="sub">Not from this page, and here is why.</p>

            <div class="st-note bad">
                <b>Restore is not offered through the browser.</b>
                Uploading a SQL file to a web form and executing it is the most destructive action this panel
                could contain: one wrong file replaces every account, session and message, and the backup that
                would have saved you is gone in the same motion. There is no undo. It belongs at a command line,
                where the person running it can see exactly what they are about to overwrite.
            </div>

            <p style="font-size:13px;color:var(--gray-600);line-height:1.65;margin:0 0 10px;">
                To restore a backup you downloaded above, or one from
                <code><?= htmlspecialchars($bkDir) ?>\database</code>, from the XAMPP shell:
            </p>
            <pre style="margin:0;padding:13px 15px;background:#0E2E6B;color:#D9E4F5;border-radius:10px;font-size:12.5px;overflow-x:auto;line-height:1.6;"><code>mysql -u root <?= htmlspecialchars($dbName) ?> &lt; peerconnect-backup.sql</code></pre>
            <p style="font-size:12px;color:var(--gray-400);margin:9px 0 0;line-height:1.6;">
                Take a fresh backup first. Restoring replaces everything currently in
                <code><?= htmlspecialchars($dbName) ?></code> — it does not merge.
            </p>
        </div>

        <div class="st-card">
            <h2>What is in the database</h2>
            <p class="sub">Row counts are MySQL's own estimate for InnoDB tables, so they are approximate.</p>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="text-align:left;color:var(--gray-400);font-size:11px;text-transform:uppercase;letter-spacing:.05em;">
                            <th style="padding:8px 10px 8px 0;font-weight:700;">Table</th>
                            <th style="padding:8px 10px;font-weight:700;text-align:right;">Rows (approx.)</th>
                            <th style="padding:8px 0 8px 10px;font-weight:700;text-align:right;">Size</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tables as $t): ?>
                            <tr style="border-top:1px solid var(--gray-100);">
                                <td style="padding:9px 10px 9px 0;font-family:ui-monospace,monospace;font-size:12.5px;color:var(--gray-800);"><?= htmlspecialchars($t['t']) ?></td>
                                <td style="padding:9px 10px;text-align:right;font-variant-numeric:tabular-nums;color:var(--gray-600);"><?= number_format((int)$t['n']) ?></td>
                                <td style="padding:9px 0 9px 10px;text-align:right;font-variant-numeric:tabular-nums;color:var(--gray-500);"><?= bk_size((int)$t['bytes']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ══════════ Side ══════════ -->
    <div class="st-stack">
        <div class="st-card">
            <h2>A backup routine</h2>
            <p class="sub">A scheduled job writes copies to <code><?= htmlspecialchars($bkDir) ?></code>; the card below shows whether they are arriving.</p>
            <?php foreach ([
                ['Before any change you cannot undo', 'Take one from this page first. It takes seconds.'],
                ['Weekly, kept off this machine', 'A backup on the same disk as the database is not a backup.'],
                ['Copy public/uploads too', 'The SQL file has no images in it.'],
                ['Try a restore once', 'A backup nobody has restored is a hope, not a plan.'],
            ] as $i => [$t, $d]): ?>
                <div class="st-row">
                    <span class="st-dot" style="background:#EAF1FB;color:#1A5C9A;font-weight:700;font-size:12px;"><?= $i + 1 ?></span>
                    <span style="min-width:0;flex:1;"><b><?= $t ?></b><span class="h"><?= $d ?></span></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="st-card">
            <h2>Scheduled backups</h2>
            <?php if ($bkLastAt === null): ?>
                <div class="st-note warn">
                    <b>No automatic backup found.</b>
                    Nothing has been written to <code><?= htmlspecialchars($bkDir) ?></code> yet.
                    <code>scripts/backup.php</code> writes them there, and <code>scripts/README.md</code>
                    sets up the nightly Task Scheduler entry that runs it.
                </div>
            <?php else: ?>
                <div class="st-row">
                    <span class="st-dot" style="background:#EAF1FB;color:#1A5C9A;"><?= ss_icon('chart') ?></span>
                    <span style="min-width:0;flex:1;">
                        <b>Database · <?= htmlspecialchars(bk_when($bkLastAt)) ?></b>
                        <span class="h"><?= htmlspecialchars(basename($bkDumps[0])) ?>, <?= bk_size((int)filesize($bkDumps[0])) ?> · <?= count($bkDumps) ?> <?= count($bkDumps) === 1 ? 'copy' : 'copies' ?> kept, nightly</span>
                    </span>
                </div>
                <div class="st-row">
                    <span class="st-dot" style="background:#EAF6FC;color:#087FC1;"><?= ss_icon('cal') ?></span>
                    <span style="min-width:0;flex:1;">
                        <?php if ($bkZipAt !== null): ?>
                            <b>Uploaded files · <?= htmlspecialchars(bk_when($bkZipAt)) ?></b>
                            <span class="h"><?= htmlspecialchars(basename($bkZips[0])) ?>, <?= bk_size((int)filesize($bkZips[0])) ?> · <?= count($bkZips) ?> <?= count($bkZips) === 1 ? 'archive' : 'archives' ?> kept, weekly</span>
                        <?php else: ?>
                            <b>Uploaded files · no archive yet</b>
                            <span class="h">The next scheduled backup creates one.</span>
                        <?php endif; ?>
                    </span>
                </div>

                <?php if ($bkFailed): ?>
                    <div class="st-note bad" style="margin-top:12px;">
                        <b>The most recent backup attempt failed.</b>
                        <?= htmlspecialchars(trim(substr($bkLastLine, strpos($bkLastLine, 'FAILED:') + 7))) ?>
                        The copies listed above are the newest ones that succeeded.
                    </div>
                <?php elseif ($bkStale): ?>
                    <div class="st-note warn" style="margin-top:12px;">
                        <b>No backup in the last 36 hours.</b>
                        The nightly job should have run by now. Check that the computer was on overnight and that the
                        "PeerConnect Backup" task is still enabled in Task Scheduler.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <p class="sub" style="margin:12px 0 0;">
                These copies are on this computer's own disk, so they do not survive losing it. Copy the folder
                somewhere else regularly.
            </p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/layout_end.php'; ?>
