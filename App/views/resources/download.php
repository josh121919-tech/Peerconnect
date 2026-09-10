<?php
// Resources — serve a file and count the download.
// Files are never linked to directly: routing every fetch through here is
// what makes download_count real, and keeps the files behind an auth check.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";

if (empty($_SESSION['user_id'])) {
    header("Location: " . url('welcomepage'));
    exit;
}

$resource_id = (int)($_GET['id'] ?? 0);
if (!$resource_id) {
    header("Location: " . url('resources'));
    exit;
}

$stmt = $con->prepare("
    SELECT file_path, original_name, file_type
    FROM resources
    WHERE resource_id = ? AND is_active = 1
");
$stmt->bind_param("i", $resource_id);
$stmt->execute();
$resource = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$resource) {
    http_response_code(404);
    exit('Resource not found.');
}

// SECURITY: basename() so a stored path can never walk out of the folder.
$path = __DIR__ . '/../../../public/uploads/resources/' . basename($resource['file_path']);
if (!is_file($path)) {
    http_response_code(404);
    exit('The file for this resource is missing.');
}

$upd = $con->prepare("UPDATE resources SET download_count = download_count + 1 WHERE resource_id = ?");
$upd->bind_param("i", $resource_id);
$upd->execute();
$upd->close();

$mime = $resource['file_type'] === 'pdf'
    ? 'application/pdf'
    : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

// Strip anything that could break out of the header; keep a sane fallback.
$download_name = preg_replace('/[^A-Za-z0-9 ._-]/', '_', $resource['original_name']);
if ($download_name === '') {
    $download_name = 'resource.' . $resource['file_type'];
}

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $download_name . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
