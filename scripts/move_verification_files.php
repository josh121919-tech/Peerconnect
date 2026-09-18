<?php
/**
 * scripts/move_verification_files.php — moves verification documents out of
 * the public folder.
 *
 *     php scripts/move_verification_files.php --dry-run   show what would happen
 *     php scripts/move_verification_files.php             do it
 *
 * Students' ID and registration documents used to be saved in
 * public/uploads/verification, where anyone with the address could download
 * them. They now live in storage/verification and are served only to their
 * owner and admins (see App/services/VerificationFiles.php). This moves what
 * is still in the old folder:
 *
 *   - a file an application points at goes to storage/verification;
 *   - a file nothing points at is not deleted: it goes to
 *     <backup folder>/verification_unreferenced_<date>/, outside the web
 *     root, to be checked and removed by a person.
 *
 * It also lists applications that point at a file that exists in neither
 * folder. Running it again only moves what is left, so it is safe to repeat.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/App/config/db.php';

$dryRun = in_array('--dry-run', $argv, true);
$from   = VerificationFiles::legacyDir();
$to     = VerificationFiles::dir();
$aside  = pc_backup_dir() . DIRECTORY_SEPARATOR . 'verification_unreferenced_' . date('Y-m-d');

$referenced = array_flip(VerificationRepository::allFileNames($con));
$files = is_dir($from) ? array_values(array_filter(scandir($from), fn($f) => is_file("$from/$f") && $f !== '.htaccess')) : [];

$moved = $setAside = $failed = 0;
foreach ($files as $name) {
    $isUsed = isset($referenced[$name]);
    $dest   = $isUsed ? $to : $aside;
    echo ($dryRun ? '[dry run] ' : '') . ($isUsed ? 'move to storage: ' : 'set aside:       ') . $name . "\n";
    if ($dryRun) {
        $isUsed ? $moved++ : $setAside++;
        continue;
    }
    if (!is_dir($dest) && !mkdir($dest, 0750, true) && !is_dir($dest)) {
        echo "  FAILED: cannot create $dest\n";
        $failed++;
        continue;
    }
    if (file_exists("$dest/$name")) {
        echo "  FAILED: $dest/$name already exists\n";
        $failed++;
        continue;
    }
    if (!rename("$from/$name", "$dest/$name")) {
        echo "  FAILED: could not move it\n";
        $failed++;
        continue;
    }
    $isUsed ? $moved++ : $setAside++;
}

$missing = array_filter(array_keys($referenced), fn($n) => VerificationFiles::path($n) === null && !($dryRun && in_array($n, $files, true)));
foreach ($missing as $name) {
    echo "missing from disk (an application points at it): $name\n";
}

echo ($dryRun ? 'Dry run: would move ' : 'Moved ') . "$moved to storage, "
    . ($dryRun ? 'would set aside ' : 'set aside ') . "$setAside"
    . ($setAside ? " in $aside" : '') . ", $failed failed, " . count($missing) . " referenced file(s) missing.\n";
exit($failed ? 1 : 0);
