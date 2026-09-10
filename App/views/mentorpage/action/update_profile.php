<?php
// File location: /case/case/mentorpage/action/update_profile.php
session_start();
include __DIR__ . "/../../db.php"; // → /case/case/db.php

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh and try again.']);
    exit;
}

$user_id    = (int) $_SESSION['user_id'];
$full_name  = trim($_POST['full_name']  ?? '');
$student_id = trim($_POST['student_id'] ?? '');
$course     = trim($_POST['course']     ?? '');
$year_level = trim($_POST['year_level'] ?? '');
$club       = trim($_POST['club']       ?? '');

if (!$full_name || !$student_id || !$course || !$year_level) {
    echo json_encode(['success' => false, 'message' => 'All required fields must be filled.']);
    exit;
}

// ── Image upload ──────────────────────────────────────────────────────────────
$profile_image_path = null;

if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
    $allowed      = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $mime         = mime_content_type($_FILES['profile_image']['tmp_name']);
    $ext          = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));

    if (!in_array($mime, $allowed) || !in_array($ext, $allowed_exts)) {
        echo json_encode(['success' => false, 'message' => 'Invalid image type.']);
        exit;
    }

    $upload_dir = PUBLIC_PATH . '/uploads/profiles/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $filename = 'user_' . $user_id . '_' . time() . '.' . $ext;

    if (!move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_dir . $filename)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save image.']);
        exit;
    }

    $profile_image_path = asset('uploads/profiles/' . $filename);
}

// ── Check if a profile row already exists for this user ──────────────────────
$check = $con->prepare("SELECT profile_id, profile_image FROM profile WHERE user_id = ? LIMIT 1");
$check->bind_param("i", $user_id);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
$check->close();

if ($existing) {
    // UPDATE existing row
    $keep_image = $profile_image_path ?? $existing['profile_image'];

    $stmt = $con->prepare("
        UPDATE profile 
        SET full_name = ?, student_id = ?, course = ?, year_level = ?, club = ?, profile_image = ?
        WHERE user_id = ?
    ");
    $stmt->bind_param("ssssssi", $full_name, $student_id, $course, $year_level, $club, $keep_image, $user_id);
} else {
    // INSERT new row
    $stmt = $con->prepare("
        INSERT INTO profile (user_id, full_name, student_id, course, year_level, club, profile_image)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("issssss", $user_id, $full_name, $student_id, $course, $year_level, $club, $profile_image_path);
}

if ($stmt->execute()) {
    // The page reloads on success, so the confirmation is queued for that
    // load rather than shown and immediately thrown away.
    pc_flash('success', 'Your changes are live on your profile.', 'Profile updated');
    echo json_encode(['success' => true, 'message' => 'Profile updated.', 'profile_image' => $profile_image_path]);
} else {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $stmt->error]);
}

$stmt->close();
$con->close();
