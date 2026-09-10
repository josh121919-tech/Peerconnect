<?php
// Resources — upload a PDF/DOCX study file.
// Both mentees and mentors may upload; the row records who did.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";

$resources_url = url('resources');

function pc_upload_fail(string $msg): void
{
    global $resources_url;
    pc_flash('error', $msg);
    header("Location: " . $resources_url);
    exit;
}

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['mentee', 'mentor'], true)) {
    header("Location: " . url('welcomepage'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . $resources_url);
    exit;
}

// SECURITY: CSRF — this writes a row and a file to disk.
if (!verify_csrf()) {
    pc_upload_fail('Security token mismatch. Please refresh and try again.');
}

// SECURITY: cap how many files one account can push in a short window.
if (!rate_limit('resource_upload_' . (int)$_SESSION['user_id'], 10, 600)) {
    pc_upload_fail('Too many uploads in a short time. Please try again later.');
}

$uploader_id = (int)$_SESSION['user_id'];
$title       = trim(strip_tags($_POST['title'] ?? ''));
$description = trim(strip_tags($_POST['description'] ?? ''));
$club        = trim($_POST['club'] ?? '');

if ($title === '')                              pc_upload_fail('Please give the resource a title.');
if (mb_strlen($title) > 200)                    pc_upload_fail('Title is too long (200 characters max).');
if (mb_strlen($description) > 1000)             pc_upload_fail('Description is too long (1000 characters max).');
if (!in_array($club, PC_CLUBS, true))           pc_upload_fail('Please choose which club this resource belongs to.');

if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
    pc_upload_fail('Please choose a PDF or DOCX file to upload.');
}
if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    pc_upload_fail($_FILES['file']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['file']['error'] === UPLOAD_ERR_FORM_SIZE
        ? 'That file is too large to upload.'
        : 'The file could not be uploaded. Please try again.');
}

$max_bytes = 10 * 1024 * 1024; // 10 MB
if ($_FILES['file']['size'] > $max_bytes) {
    pc_upload_fail('File is too large. The limit is 10 MB.');
}

// SECURITY: extension AND MIME must both check out — same belt-and-braces
// pattern the verification upload uses. A .docx is a zip container, so some
// PHP builds report it as application/zip; that is only accepted when the
// extension already says docx.
$original_name = $_FILES['file']['name'];
$ext  = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
$mime = mime_content_type($_FILES['file']['tmp_name']);

$allowed = [
    'pdf'  => ['application/pdf'],
    'docx' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'application/octet-stream',
    ],
];

if (!isset($allowed[$ext]) || !in_array($mime, $allowed[$ext], true)) {
    pc_upload_fail('Only PDF and DOCX files are allowed.');
}

$upload_dir = __DIR__ . '/../../../public/uploads/resources/';
if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
    pc_upload_fail('Upload folder is unavailable. Please contact an administrator.');
}

// Stored name is generated, never taken from the user — the original is kept
// in the DB and only reattached at download time.
$stored_name = 'res_' . bin2hex(random_bytes(16)) . '.' . $ext;
if (!move_uploaded_file($_FILES['file']['tmp_name'], $upload_dir . $stored_name)) {
    pc_upload_fail('The file could not be saved. Please try again.');
}

$size = (int)$_FILES['file']['size'];
$stmt = $con->prepare("
    INSERT INTO resources (uploader_id, club, title, description, file_path, original_name, file_type, file_size)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param("issssssi", $uploader_id, $club, $title, $description, $stored_name, $original_name, $ext, $size);

if (!$stmt->execute()) {
    @unlink($upload_dir . $stored_name); // don't leave an orphan file behind
    $stmt->close();
    pc_upload_fail('The resource could not be saved. Please try again.');
}
$stmt->close();

pc_flash('success', '"' . $title . '" was shared with the ' . $club . '.', 'Resource uploaded');
header("Location: " . $resources_url . '?club=' . urlencode($club));
exit;
