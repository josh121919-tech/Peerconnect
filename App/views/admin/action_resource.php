<?php

/**
 * action_resource.php — an admin takes a resource out of the library.
 *
 * The member-side resources/delete.php is scoped to uploader_id, so a mentor
 * can only remove their own file. That is right for them and is exactly what
 * an admin cannot use: moderating means acting on somebody else's upload.
 * This is that action, and it is the only thing the admin screen can do to a
 * resource — it does not upload, edit or re-club anything.
 *
 * SECURITY: admin-only, POST with a CSRF token, prepared statements. The file
 * on disk is removed by basename() only, so a crafted file_path cannot reach
 * outside the uploads directory.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_admin();

$back = url('admin-resources');
// The screen it was pressed on, so the admin returns to the page and filters
// they were looking at rather than the top of an unfiltered list.
$raw = (string)($_POST['back'] ?? '');
if ($raw !== '' && str_starts_with($raw, '?') && !str_contains($raw, "\n")) {
    $back .= $raw;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    pc_flash('error', 'Security token mismatch. Please refresh and try again.');
    header('Location: ' . $back);
    exit;
}

$id  = (int)($_POST['resource_id'] ?? 0);
$row = $id > 0 ? ResourceAdminRepository::find($con, $id) : null;

if (!$row) {
    pc_flash('error', 'That resource could not be found. It may already have been removed.');
    header('Location: ' . $back);
    exit;
}

ResourceAdminRepository::remove($con, $id);

// Bookmarks point at a row that is gone, so they go with it.
$bm = $con->prepare("DELETE FROM resource_bookmarks WHERE resource_id = ?");
$bm->bind_param('i', $id);
$bm->execute();
$bm->close();

@unlink(__DIR__ . '/../../../public/uploads/resources/' . basename((string)$row['file_path']));

pc_admin_log('took resource #' . $id . ' "' . $row['title'] . '" out of the library');
pc_flash('success', '“' . $row['title'] . '” was removed from the library.', 'Resource taken down');

header('Location: ' . $back);
exit;
