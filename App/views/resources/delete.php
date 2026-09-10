<?php
// Resources — remove a resource you uploaded.
// Scoped to uploader_id so nobody can delete someone else's file.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";

$resources_url = url('resources');

if (empty($_SESSION['user_id'])) {
    header("Location: " . url('welcomepage'));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    pc_flash('error', 'Security token mismatch. Please refresh and try again.');
    header("Location: " . $resources_url);
    exit;
}

$user_id     = (int)$_SESSION['user_id'];
$resource_id = (int)($_POST['resource_id'] ?? 0);

$stmt = $con->prepare("SELECT file_path FROM resources WHERE resource_id = ? AND uploader_id = ?");
$stmt->bind_param("ii", $resource_id, $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    pc_flash('error', 'You can only remove resources you uploaded.');
    header("Location: " . $resources_url);
    exit;
}

$del = $con->prepare("DELETE FROM resources WHERE resource_id = ? AND uploader_id = ?");
$del->bind_param("ii", $resource_id, $user_id);
$del->execute();
$del->close();

$con->query("DELETE FROM resource_bookmarks WHERE resource_id = " . (int)$resource_id);
@unlink(__DIR__ . '/../../../public/uploads/resources/' . basename($row['file_path']));

pc_flash('success', 'The file and its bookmarks were removed.', 'Resource deleted');
header("Location: " . $resources_url);
exit;
