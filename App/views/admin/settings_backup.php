<?php

/**
 * admin/settings_backup.php — System Settings → Backup & Restore.
 *
 * Backup is real: it writes a genuine SQL dump of every table, generated in
 * PHP so it needs no mysqldump on PATH, and streams it as a download.
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
        ['Uploaded files', number_format($fileN), bk_size((int)$fileBytes) . ' in public/uploads', '#EAF6FB', '#0087CF', 'cal'],
        ['Backups taken here', '—', 'Downloads are not recorded', '#F3F4F6', '#565B66', 'clock'],
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
                To restore a backup you downloaded above, from the XAMPP shell:
            </p>
            <pre style="margin:0;padding:13px 15px;background:#0B1440;color:#D9E4F5;border-radius:10px;font-size:12.5px;overflow-x:auto;line-height:1.6;"><code>mysql -u root <?= htmlspecialchars($dbName) ?> &lt; peerconnect-backup.sql</code></pre>
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
            <p class="sub">Nothing here runs on a schedule — this app has no task runner.</p>
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
            <div class="st-note">
                <b>Not automatic.</b>
                The reference design showed a "Last backup: successful" tile and a daily schedule. Nothing in
                this install runs on a timer — there is no cron job and no queue — so that tile would report a
                backup that never happened. To automate it, point a Windows Task Scheduler entry at
                <code>mysqldump</code>; the page above is the manual equivalent.
            </div>
        </div>
    </div>
</div>
