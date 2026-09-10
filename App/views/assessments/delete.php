<?php
// Delete an assessment you wrote, along with its questions, options and attempts.
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
$assessment_id = (int)($_POST['assessment_id'] ?? 0);

$own = $con->prepare("SELECT title FROM assessments WHERE assessment_id = ? AND mentor_id = ?");
$own->bind_param("ii", $assessment_id, $mentor_id);
$own->execute();
$row = $own->get_result()->fetch_assoc();
$own->close();

if (!$row) {
    pc_flash('error', 'You can only delete assessments you created.');
    header("Location: " . url('assessments'));
    exit;
}

$con->begin_transaction();
try {
    // Answers → attempts → options → questions → assessment.
    $con->query("DELETE FROM assessment_answers WHERE attempt_id IN
                 (SELECT attempt_id FROM assessment_attempts WHERE assessment_id = $assessment_id)");
    $con->query("DELETE FROM assessment_attempts WHERE assessment_id = $assessment_id");
    $con->query("DELETE FROM assessment_options WHERE question_id IN
                 (SELECT question_id FROM assessment_questions WHERE assessment_id = $assessment_id)");
    $con->query("DELETE FROM assessment_questions WHERE assessment_id = $assessment_id");

    $del = $con->prepare("DELETE FROM assessments WHERE assessment_id = ? AND mentor_id = ?");
    $del->bind_param("ii", $assessment_id, $mentor_id);
    $del->execute();
    $del->close();

    $con->commit();
    pc_flash('success', '"' . $row['title'] . '" was deleted.');
} catch (Throwable $e) {
    $con->rollback();
    pc_flash('error', 'The assessment could not be deleted.');
}

header("Location: " . url('assessments'));
exit;
