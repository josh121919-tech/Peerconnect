<?php

/**
 * action_assessment.php — the one lever an admin has over an assessment.
 *
 * Admins do not write assessments and cannot edit a mentor's questions, so
 * the only moderation that makes sense is whether a published assessment
 * stays visible to mentees. Nothing is deleted: attempts already made are
 * somebody's work and stay on the record either way.
 *
 * SECURITY: admin-only, POST-only, CSRF-checked, prepared statements.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../../services/NotificationService.php';

require_admin();
require_post();

if (!verify_csrf()) {
    http_response_code(403);
    exit('CSRF token mismatch.');
}

$id     = (int)($_POST['assessment_id'] ?? 0);
$action = $_POST['action'] ?? '';
$back   = $_POST['back'] ?? '';

if ($back === '' || strpos($back, BASE_URL . '/') !== 0) {
    $back = url('admin-assessments');
}

if (!$id || !in_array($action, ['publish', 'unpublish'], true)) {
    pc_flash('error', 'That action could not be carried out.');
    header('Location: ' . $back);
    exit;
}

$st = $con->prepare("
    SELECT a.assessment_id, a.title, a.status, a.mentor_id,
           (SELECT COUNT(*) FROM assessment_questions q WHERE q.assessment_id = a.assessment_id) AS questions
    FROM assessments a WHERE a.assessment_id = ? LIMIT 1
");
$st->bind_param('i', $id);
$st->execute();
$a = $st->get_result()->fetch_assoc();
$st->close();

if (!$a) {
    pc_flash('error', 'That assessment no longer exists.');
    header('Location: ' . $back);
    exit;
}

$title = $a['title'] ?: 'an assessment';

if ($action === 'publish') {
    if ($a['status'] === 'published') {
        pc_flash('warning', 'That assessment is already published.');
        header('Location: ' . $back);
        exit;
    }
    // Publishing an empty paper would hand mentees something they cannot take.
    if ((int)$a['questions'] === 0) {
        pc_flash('error', 'That assessment has no questions yet, so it cannot be published.');
        header('Location: ' . $back);
        exit;
    }

    $up = $con->prepare("UPDATE assessments SET status = 'published', published_at = COALESCE(published_at, NOW()) WHERE assessment_id = ?");
    $up->bind_param('i', $id);
    $up->execute();
    $ok = $up->affected_rows > 0;
    $up->close();

    if ($ok) {
        NotificationService::send($con, (int)$a['mentor_id'], 'assessment_published',
            'Assessment Published',
            'An admin published "' . $title . '". Your mentees can take it now.',
            url('assessments'));
        pc_admin_log('published assessment #' . $id . ' "' . $title . '"');
        pc_flash('success', '"' . $title . '" is live for that mentor’s mentees.', 'Published');
    } else {
        pc_flash('warning', 'Nothing changed.');
    }
} else {
    if ($a['status'] !== 'published') {
        pc_flash('warning', 'That assessment is not published, so there is nothing to take offline.');
        header('Location: ' . $back);
        exit;
    }

    $up = $con->prepare("UPDATE assessments SET status = 'draft' WHERE assessment_id = ?");
    $up->bind_param('i', $id);
    $up->execute();
    $ok = $up->affected_rows > 0;
    $up->close();

    if ($ok) {
        NotificationService::send($con, (int)$a['mentor_id'], 'assessment_unpublished',
            'Assessment Taken Offline',
            'An admin took "' . $title . '" offline. Mentees can no longer start it. Attempts already made are kept.',
            url('assessments'));
        pc_admin_log('took assessment #' . $id . ' "' . $title . '" offline');
        pc_flash('success', '"' . $title . '" is back to draft. Attempts already made are kept.', 'Taken offline');
    } else {
        pc_flash('warning', 'Nothing changed.');
    }
}

header('Location: ' . $back);
exit;
