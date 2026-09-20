<?php
// Delete an assessment you wrote, along with its questions, options and every
// attempt made at it. The mentee-facing results go with it, so the page asks
// for confirmation with that count in it first.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mentor') {
    header("Location: " . url('welcomepage'));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    pc_flash('error', 'Security token mismatch. Please refresh and try again.');
    header("Location: " . url('assessments'));
    exit;
}

$mentor_id     = (int)$_SESSION['user_id'];
$assessment_id = is_scalar($_POST['assessment_id'] ?? null) ? (int)$_POST['assessment_id'] : 0;

$assessment = AssessmentRepository::ownedBy($con, $assessment_id, $mentor_id);
if (!$assessment) {
    pc_flash('error', 'You can only delete assessments you created.');
    header("Location: " . url('assessments'));
    exit;
}

// Said plainly in the toast as well as the dialog, because this is the point
// at which the results stop existing.
$submitted = AssessmentRepository::countSubmitted($con, $assessment_id);

// Questions, options, attempts and answers all hang off this row, and the
// database removes them with it.
if (AssessmentRepository::deleteOwned($con, $assessment_id, $mentor_id) < 1) {
    pc_flash('error', 'The assessment could not be deleted.');
    header("Location: " . url('assessments'));
    exit;
}

pc_flash('success', '"' . $assessment['title'] . '" was deleted'
    . ($submitted > 0
        ? ', along with ' . $submitted . ' submitted result' . ($submitted === 1 ? '' : 's') . '.'
        : '.'));
header("Location: " . url('assessments'));
exit;
