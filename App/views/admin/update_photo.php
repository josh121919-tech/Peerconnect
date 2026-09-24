<?php

/**
 * admin/update_photo.php — an administrator changes their own picture.
 *
 * The member pages each have their own copy of this inside a much larger
 * profile save; administrators had none, which is why an administrator's
 * avatar was always their initials no matter what.
 *
 * This writes one column of one row and nothing else. It cannot be used to
 * change anybody else's picture: the id comes from the session.
 *
 * SECURITY: signed-in administrators only, POST with CSRF, rate limited. The
 * file is checked by its actual contents, not its name or the type the
 * browser claimed, and it is stored under a name this code chooses.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrators only.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request. Please refresh and try again.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

if (!rate_limit('admin_photo_' . $user_id, 10, 300)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many changes in a short time. Please wait a moment.']);
    exit;
}

$file = $_FILES['profile_image'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['success' => false, 'message' => 'Choose a picture first.']);
    exit;
}
if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    echo json_encode(['success' => false, 'message' => 'That upload did not arrive. Please try again.']);
    exit;
}
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'The picture must be under 5 MB.']);
    exit;
}

/*
 * getimagesize() reads the file's own header. The name and the browser's
 * Content-Type are both supplied by whoever is uploading, so neither is
 * evidence of anything: a .php file renamed to .jpg passes both and fails
 * this.
 */
$info = @getimagesize($file['tmp_name']);
$ext  = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_GIF  => 'gif',
    IMAGETYPE_WEBP => 'webp',
][$info[2] ?? 0] ?? null;

if ($ext === null) {
    echo json_encode(['success' => false, 'message' => 'That file is not a JPG, PNG, GIF or WEBP image.']);
    exit;
}

$dir = PUBLIC_PATH . '/uploads/profiles/';
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    error_log('admin/update_photo: cannot create ' . $dir);
    echo json_encode(['success' => false, 'message' => 'The picture could not be saved.']);
    exit;
}

$filename = 'user_' . $user_id . '_' . time() . '.' . $ext;
if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
    error_log('admin/update_photo: move failed for ' . $filename);
    echo json_encode(['success' => false, 'message' => 'The picture could not be saved.']);
    exit;
}

$path = asset('uploads/profiles/' . $filename);

try {
    ProfileRepository::ensureRow($con, $user_id);
    ProfileRepository::savePhoto($con, $user_id, $path);
} catch (Throwable $e) {
    @unlink($dir . $filename);
    error_log('admin/update_photo: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'The picture could not be saved.']);
    exit;
}

echo json_encode(['success' => true, 'image' => $path]);
