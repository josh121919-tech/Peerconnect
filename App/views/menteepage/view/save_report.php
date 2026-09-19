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
if (!verify_csrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token invalid.']);
    exit;
}

$reported_by = (int)$_SESSION['user_id'];

// A field sent as a list counts as missing (strip_tags() of a list used to
// end the request with a server error). The text is stored as typed:
// strip_tags() cut everything after a "<", so "they said <3 then left" was
// saved as "they said ". Every page that shows it escapes it.
$field            = fn(string $name): string => is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
$reported_user_id = ctype_digit($field('mentor_id')) ? (int)$field('mentor_id') : 0;
$issue_type       = $field('issue_type');
$description      = $field('reason');

if (!$reported_user_id || $issue_type === '' || $description === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid data.']);
    exit;
}
if (!isset(ReportService::ISSUE_TYPES[$issue_type])) {
    echo json_encode(['success' => false, 'message' => 'Please choose one of the listed issue types.']);
    exit;
}
if (mb_strlen($description) > ReportService::DESCRIPTION_MAX) {
    echo json_encode(['success' => false, 'message' => 'Please keep the description under ' . number_format(ReportService::DESCRIPTION_MAX) . ' characters.']);
    exit;
}

// The account must be a real member, and not the reporter. A missing one
// used to end in a server error from the database.
$target = UserRepository::moderationTarget($con, $reported_user_id);
if (!$target || ModerationService::isDeleted($target) || !in_array($target['role'], ['mentee', 'mentor'], true)) {
    echo json_encode(['success' => false, 'message' => 'That account could not be found.']);
    exit;
}
if ($reported_user_id === $reported_by) {
    echo json_encode(['success' => false, 'message' => 'You cannot report yourself.']);
    exit;
}

// Nothing limited how many reports one member could send.
if (!rate_limit('report_' . $reported_by, ReportService::REPORT_LIMIT, ReportService::REPORT_WINDOW)) {
    echo json_encode(['success' => false, 'message' => 'You have sent several reports in a short time. Please wait a few minutes before sending another.']);
    exit;
}

$proof = null;
$check = ReportService::inspectProof($_FILES['proof'] ?? null);
if ($check['present']) {
    if (isset($check['error'])) {
        echo json_encode(['success' => false, 'message' => $check['error']]);
        exit;
    }
    $proof = ReportService::storeProof($_FILES['proof'], $check['ext']);
    if ($proof === null) {
        echo json_encode(['success' => false, 'message' => 'Failed to save image.']);
        exit;
    }
}

ModerationRepository::fileReport($con, $reported_user_id, $reported_by, $issue_type, $description, $proof);

echo json_encode(['success' => true]);
