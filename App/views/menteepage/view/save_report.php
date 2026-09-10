<?php
session_start();
include __DIR__ . "/../../db.php";

header('Content-Type: application/json');

// SECURITY: Auth check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// SECURITY: CSRF check
$csrfSubmitted = $_POST['csrf_token'] ?? '';
$csrfExpected  = $_SESSION['csrf_token'] ?? '';
if ($csrfExpected === '' || !hash_equals($csrfExpected, $csrfSubmitted)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token invalid.']);
    exit;
}

$reported_user_id = isset($_POST['mentor_id'])   ? (int)$_POST['mentor_id']        : 0;
$issue_type       = isset($_POST['issue_type'])  ? trim(strip_tags($_POST['issue_type']))  : '';
$description      = isset($_POST['reason'])      ? trim(strip_tags($_POST['reason']))      : '';
$reported_by      = (int)$_SESSION['user_id'];

if (!$reported_user_id || !$issue_type || !$description || !$reported_by) {
    echo json_encode(['success' => false, 'message' => 'Invalid data.']);
    exit;
}

$proof_path = null;

if (!empty($_FILES['proof']['tmp_name'])) {
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $mime    = mime_content_type($_FILES['proof']['tmp_name']);

    if (!in_array($mime, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Only JPEG, PNG, WEBP, or GIF images are allowed.']);
        exit;
    }

    if ($_FILES['proof']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Image must be under 5 MB.']);
        exit;
    }

    $upload_dir = PUBLIC_PATH . '/uploads/reports/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $ext        = pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION);
    $filename   = uniqid('proof_', true) . '.' . strtolower($ext);
    $dest       = $upload_dir . $filename;

    if (!move_uploaded_file($_FILES['proof']['tmp_name'], $dest)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save image.']);
        exit;
    }

    $proof_path = 'uploads/reports/' . $filename;
}

$stmt = $con->prepare("
    INSERT INTO reports (reported_user_id, reported_by, issue_type, description, proof, status, created_at)
    VALUES (?, ?, ?, ?, ?, 'pending', NOW())
");
$stmt->bind_param('iisss', $reported_user_id, $reported_by, $issue_type, $description, $proof_path);
$res = $stmt->execute();

echo $res
    ? json_encode(['success' => true])
    : json_encode(['success' => false, 'message' => 'Failed to save report.']);

$stmt->close();
