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

if (!verify_csrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh and try again.']);
    exit;
}

$user_id    = (int) $_SESSION['user_id'];

// A field sent as a list counts as missing.
$field      = fn(string $name): string => is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
$full_name  = $field('full_name');
$student_id = $field('student_id');
$course     = $field('course');
$year_level = $field('year_level');
$club       = $field('club');

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

// ── Update the profile row, or create it ─────────────────────────────────────
// A failed write throws, so there is no "false" to report.
$existing = ProfileRepository::fields($con, $user_id, ['profile_id', 'profile_image']);

if ($existing) {
    ProfileRepository::updateDetails($con, $user_id, $full_name, $student_id, $course, $year_level, $club,
        $profile_image_path ?? $existing['profile_image']);
} else {
    ProfileRepository::createWithDetails($con, $user_id, $full_name, $student_id, $course, $year_level, $club,
        $profile_image_path);
}

// The page reloads on success, so the confirmation is queued for that
// load rather than shown and immediately thrown away.
pc_flash('success', 'Your changes are live on your profile.', 'Profile updated');
echo json_encode(['success' => true, 'message' => 'Profile updated.', 'profile_image' => $profile_image_path]);
$con->close();
