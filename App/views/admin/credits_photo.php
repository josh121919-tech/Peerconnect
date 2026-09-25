<?php

/**
 * admin/credits_photo.php — an administrator sets one person's picture on the
 * Credits & Developers page.
 *
 * This writes one settings row and one file, and nothing else. The person it
 * applies to is named by a slug that must be one of the four in
 * PC_CREDITS_TEAM — a slug from anywhere else is refused rather than used to
 * invent a settings key or a filename.
 *
 * SECURITY: signed-in administrators only, POST with CSRF, rate limited. The
 * file is judged by its own contents rather than by its name or the type the
 * browser claimed, and it is stored under a name this code chooses. It
 * follows admin/update_photo.php, which does the same job for an
 * administrator's own avatar.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/credits_data.php';
require_once __DIR__ . '/../includes/settings_store.php';
require_admin();

$back = url('admin-credits');

/** Say what went wrong on the page the administrator is coming back to. */
$stop = function (string $message) use ($back): void {
    pc_flash('error', $message);
    header('Location: ' . $back);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    $stop('That request could not be verified. Please try again.');
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!rate_limit('credits_photo_' . $user_id, 12, 300)) {
    $stop('Too many changes in a short time. Please wait a moment.');
}

/*
 * The slug decides both the settings key and the filename, so it is checked
 * against the fixed list rather than trusted. Nothing else on this page can
 * be chosen by whoever is posting.
 */
$slug   = (string)($_POST['slug'] ?? '');
$person = null;
foreach (PC_CREDITS_TEAM as $p) {
    if ($p['slug'] === $slug) { $person = $p; break; }
}
if ($person === null) {
    $stop('That is not somebody on the credits page.');
}

$file = $_FILES['photo'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    $stop('Choose a picture first.');
}
if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    $stop('That upload did not arrive. Please try again.');
}
if ($file['size'] > 5 * 1024 * 1024) {
    $stop('The picture must be under 5 MB.');
}

/*
 * getimagesize() reads the file's own header. The name and the browser's
 * Content-Type both come from whoever is uploading, so neither is evidence of
 * anything: a .php file renamed to .jpg passes both and fails this.
 */
$info = @getimagesize($file['tmp_name']);
$ext  = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_GIF  => 'gif',
    IMAGETYPE_WEBP => 'webp',
][$info[2] ?? 0] ?? null;

if ($ext === null) {
    $stop('That file is not a JPG, PNG, GIF or WEBP image.');
}

$dir = PUBLIC_PATH . '/uploads/credits/';
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    error_log('admin/credits_photo: cannot create ' . $dir);
    $stop('The picture could not be saved.');
}

/*
 * A fresh name every time, which is what makes a replacement show up straight
 * away: the old file's address is already in browser caches, so reusing it
 * would keep serving the old picture.
 *
 * The random part is not decoration. Two uploads for the same person inside
 * one second produced the same name from the clock alone — the second
 * overwrote the first, and the tidy-up below then deleted the file that had
 * just been written, leaving the page pointing at nothing.
 */
$filename = 'credit_' . $slug . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
    error_log('admin/credits_photo: move failed for ' . $filename);
    $stop('The picture could not be saved.');
}

$key      = pc_credit_photo_key($slug);
$previous = pc_setting($con, $key);

if (pc_setting_save($con, [$key => asset('uploads/credits/' . $filename)], $user_id) !== 1) {
    @unlink($dir . $filename);
    error_log('admin/credits_photo: settings write failed for ' . $key);
    $stop('The picture could not be saved.');
}

/*
 * The one it replaced is deleted, so a page of four pictures does not leave a
 * growing pile of old ones behind. Only ever a file this code wrote: the name
 * is rebuilt from the stored path, must carry this person's prefix, and must
 * sit in this directory. Never the file just written, whatever the stored
 * path said.
 */
$oldName = $previous !== '' ? basename($previous) : '';
if ($oldName !== '' && $oldName !== $filename && strpos($oldName, 'credit_' . $slug . '_') === 0) {
    if (is_file($dir . $oldName)) {
        @unlink($dir . $oldName);
    }
}

pc_admin_log('updated the credits picture for ' . $person['name']);
pc_flash('success', 'Picture updated for ' . $person['name'] . '.');
header('Location: ' . $back);
exit;
