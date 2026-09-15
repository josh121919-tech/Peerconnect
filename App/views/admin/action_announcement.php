<?php

/**
 * action_announcement.php — write, schedule, archive and restore announcements.
 *
 * Publishing notifies every account in the audience. That is the whole point
 * of the feature: an announcement nobody is told about is a page nobody
 * visits. Scheduling defers that to the moment it goes live, which the
 * announcement pages do for themselves (see pc_ann_release).
 *
 * Nothing here deletes. Archiving takes an announcement out of members' lists
 * and keeps the record, including who had read it.
 *
 * SECURITY: admin-only, POST-only, CSRF-checked, prepared statements.
 * Uploads are re-checked server-side by actual image type, never by extension.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/announcement_data.php';
require_once __DIR__ . '/../../services/NotificationService.php';

require_admin();
require_post();

if (!verify_csrf()) {
    http_response_code(403);
    exit('CSRF token mismatch.');
}

date_default_timezone_set('Asia/Manila');

$me     = (int)($_SESSION['user_id'] ?? 0);
$id     = (int)($_POST['announcement_id'] ?? 0);
$action = $_POST['action'] ?? '';
$back   = $_POST['back'] ?? '';

if ($back === '' || strpos($back, BASE_URL . '/') !== 0) {
    $back = url('admin-announcements');
}

$valid = ['publish', 'schedule', 'draft', 'archive', 'restore'];
if (!in_array($action, $valid, true)) {
    pc_flash('error', 'That action could not be carried out.');
    header('Location: ' . $back);
    exit;
}

/* ── Archive and restore act on an existing row only ──────────────────── */
if ($action === 'archive' || $action === 'restore') {
    if (!$id) {
        pc_flash('error', 'No announcement was given.');
        header('Location: ' . $back);
        exit;
    }
    $st = $con->prepare("SELECT title, status FROM announcements WHERE announcement_id = ?");
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
        pc_flash('error', 'That announcement no longer exists.');
        header('Location: ' . $back);
        exit;
    }

    if ($action === 'archive') {
        $up = $con->prepare("UPDATE announcements SET status = 'archived', archived_at = NOW(), updated_at = NOW() WHERE announcement_id = ?");
        $up->bind_param('i', $id);
        $up->execute();
        $up->close();
        pc_admin_log('archived announcement #' . $id . ' "' . $row['title'] . '"');
        pc_flash('success', '“' . $row['title'] . '” is no longer shown to members. Nothing was deleted.', 'Archived');
    } else {
        // Back to a draft, not straight back out to everyone — restoring
        // should not re-announce something silently.
        $up = $con->prepare("UPDATE announcements SET status = 'draft', archived_at = NULL, updated_at = NOW() WHERE announcement_id = ?");
        $up->bind_param('i', $id);
        $up->execute();
        $up->close();
        pc_admin_log('restored announcement #' . $id . ' "' . $row['title'] . '" as a draft');
        pc_flash('success', '“' . $row['title'] . '” is back as a draft. Publish it when you are ready.', 'Restored');
    }
    header('Location: ' . $back);
    exit;
}

/* ── Write or update ──────────────────────────────────────────────────── */
$title = trim((string)($_POST['title'] ?? ''));
$body  = trim((string)($_POST['body'] ?? ''));
$cats  = pc_ann_categories();
$auds  = pc_ann_audiences();
$cat   = array_key_exists($_POST['category'] ?? '', $cats) ? $_POST['category'] : 'General';
$aud   = array_key_exists($_POST['audience'] ?? '', $auds) ? $_POST['audience'] : 'all';
$pin   = !empty($_POST['is_pinned']) ? 1 : 0;
$whenR = trim((string)($_POST['publish_at'] ?? ''));

if ($title === '' || $body === '') {
    pc_flash('error', 'An announcement needs a title and a message.');
    header('Location: ' . $back . '#compose');
    exit;
}
if (mb_strlen($title) > 200) {
    pc_flash('error', 'Keep the title to 200 characters or fewer.');
    header('Location: ' . $back . '#compose');
    exit;
}

$publishAt = null;
if ($whenR !== '' && strtotime($whenR)) {
    $publishAt = date('Y-m-d H:i:s', strtotime($whenR));
}

if ($action === 'schedule') {
    if ($publishAt === null) {
        pc_flash('error', 'Scheduling needs a date and time.');
        header('Location: ' . $back . '#compose');
        exit;
    }
    if (strtotime($publishAt) <= time()) {
        pc_flash('error', 'That time has already passed. Pick a future time, or publish now.');
        header('Location: ' . $back . '#compose');
        exit;
    }
}

/* ── The banner image ─────────────────────────────────────────────────── */
$imagePath = null;
$keepImage = true;
if (!empty($_FILES['image']['name']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $f = $_FILES['image'];

    if ($f['size'] > 5 * 1024 * 1024) {
        pc_flash('error', 'That image is over 5 MB. Please use a smaller one.');
        header('Location: ' . $back . '#compose');
        exit;
    }

    // Checked by what the file actually is, never by the name it arrived with.
    $info = @getimagesize($f['tmp_name']);
    $ext  = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if ($ext === null) {
        pc_flash('error', 'Only JPG, PNG, GIF or WEBP images can be used as a banner.');
        header('Location: ' . $back . '#compose');
        exit;
    }

    $dir = PUBLIC_PATH . '/uploads/announcements/';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $name = 'ann_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) {
        pc_flash('error', 'The image could not be saved. Please try again.');
        header('Location: ' . $back . '#compose');
        exit;
    }
    $imagePath = asset('uploads/announcements/' . $name);
    $keepImage = false;
}

$status = $action === 'publish' ? 'published' : ($action === 'schedule' ? 'scheduled' : 'draft');

if ($id > 0) {
    /* ── Update ──────────────────────────────────────────────────────── */
    $st = $con->prepare("SELECT status, published_at, image_path, title FROM announcements WHERE announcement_id = ?");
    $st->bind_param('i', $id);
    $st->execute();
    $before = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$before) {
        pc_flash('error', 'That announcement no longer exists.');
        header('Location: ' . $back);
        exit;
    }

    $img = $keepImage ? $before['image_path'] : $imagePath;
    // Only announce it the first time it goes live — editing a published post
    // must not notify everyone all over again.
    $wasLive = $before['status'] === 'published';
    $goLive  = ($status === 'published' && !$wasLive);
    $pubAt   = $status === 'published' ? ($before['published_at'] ?: date('Y-m-d H:i:s')) : $before['published_at'];

    $up = $con->prepare("
        UPDATE announcements
           SET title = ?, body = ?, category = ?, audience = ?, status = ?, is_pinned = ?,
               image_path = ?, publish_at = ?, published_at = ?, updated_at = NOW()
         WHERE announcement_id = ?
    ");
    $up->bind_param('sssssisssi', $title, $body, $cat, $aud, $status, $pin, $img, $publishAt, $pubAt, $id);
    $up->execute();
    $up->close();

    $logRef = 'announcement #' . $id . ' "' . $title . '"';
    if ($goLive) {
        $n = pc_ann_send($con, $id, $title, $aud);
        pc_admin_log('published ' . $logRef . ' to ' . $n . ' ' . ($n === 1 ? 'person' : 'people'));
        pc_flash('success', 'Published and ' . $n . ' ' . ($n === 1 ? 'person was' : 'people were') . ' notified.', 'Announcement sent');
    } elseif ($status === 'scheduled') {
        pc_admin_log('scheduled ' . $logRef . ' for ' . date('M j, Y g:i A', strtotime($publishAt)));
        pc_flash('success', 'Scheduled for ' . date('M j, Y \a\t g:i A', strtotime($publishAt)) . '.', 'Scheduled');
    } else {
        pc_admin_log($wasLive && $status === 'draft'
            ? 'moved ' . $logRef . ' back to draft'
            : 'edited ' . $logRef);
        pc_flash('success', 'Your changes to “' . $title . '” were saved.', 'Saved');
    }

} else {
    /* ── Create ──────────────────────────────────────────────────────── */
    $pubAt = $status === 'published' ? date('Y-m-d H:i:s') : null;

    $ins = $con->prepare("
        INSERT INTO announcements
            (title, body, category, audience, status, is_pinned, image_path, created_by, created_at, publish_at, published_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
    ");
    $ins->bind_param('sssssiisss', $title, $body, $cat, $aud, $status, $pin, $imagePath, $me, $publishAt, $pubAt);
    $ins->execute();
    $newId = (int)$ins->insert_id;
    $ins->close();

    $logRef = 'announcement #' . $newId . ' "' . $title . '"';
    if ($status === 'published') {
        $n = pc_ann_send($con, $newId, $title, $aud);
        pc_admin_log('published ' . $logRef . ' to ' . $n . ' ' . ($n === 1 ? 'person' : 'people'));
        pc_flash('success', $n . ' ' . ($n === 1 ? 'person was' : 'people were') . ' notified.', 'Announcement sent');
    } elseif ($status === 'scheduled') {
        pc_admin_log('scheduled ' . $logRef . ' for ' . date('M j, Y g:i A', strtotime($publishAt)));
        pc_flash('success', 'It goes out on ' . date('M j, Y \a\t g:i A', strtotime($publishAt)) . '.', 'Scheduled');
    } else {
        pc_admin_log('saved ' . $logRef . ' as a draft');
        pc_flash('success', 'Saved as a draft. Nobody has been notified.', 'Draft saved');
    }
}

header('Location: ' . $back);
exit;
